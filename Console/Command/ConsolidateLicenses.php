<?php
/**
 * `bin/magento bougie:licenses:consolidate` — fold each customer's licenses
 * onto their single account key, shop-initiated so the local rows are updated
 * in the same pass (a sconce-side admin merge can't know about this store's
 * mirror). The migration for customers who bought before per-entitlement
 * bounds existed; idempotent — a consolidated customer has one distinct key
 * and is skipped on the next run.
 *
 * Per customer (active rows on more than one key):
 *  - target = the newest ACTIVE, UNBOUNDED key (the account key; sconce only
 *    merges onto unbounded keys). No such key -> skipped with a warning.
 *  - an ACTIVE source key is merged via the management API (bounds are
 *    materialized sconce-side: remaining time moves, no fresh period);
 *  - an already-REVOKED source (e.g. merged by an operator in the sconce
 *    admin UI) is only re-pointed when the target actually covers its row's
 *    packages; uncovered rows are marked revoked instead — never resurrected.
 *  - re-pointed rows get the target's key (re-encrypted from the inspect
 *    response, or copied from a sibling row already on the target).
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Console\Command;

use Cresset\BougieLicensing\Exception\ApiException;
use Cresset\BougieLicensing\Model\Api\Client;
use Cresset\BougieLicensing\Model\License;
use Cresset\BougieLicensing\Model\ResourceModel\License as LicenseResource;
use Cresset\BougieLicensing\Model\ResourceModel\License\CollectionFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\Encryption\EncryptorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConsolidateLicenses extends Command
{
    public function __construct(
        private readonly Client $client,
        private readonly CollectionFactory $collectionFactory,
        private readonly LicenseResource $licenseResource,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bougie:licenses:consolidate')
            ->setDescription('Fold each customer\'s licenses onto their single account key')
            ->addOption('customer-id', null, InputOption::VALUE_REQUIRED, 'Only this customer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $onlyCustomer = $input->getOption('customer-id');

        foreach ($this->customersWithMultipleKeys($onlyCustomer) as [$customerId, $storeId]) {
            $this->consolidateCustomer((int)$customerId, (int)$storeId, $dryRun, $output);
        }
        $output->writeln('<info>Done.</info>');
        return Cli::RETURN_SUCCESS;
    }

    /**
     * `(customer_id, store_id)` pairs whose active rows span more than one key.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function customersWithMultipleKeys(?string $onlyCustomer): array
    {
        $connection = $this->licenseResource->getConnection();
        $select = $connection->select()
            ->from($this->licenseResource->getMainTable(), ['customer_id', 'store_id'])
            ->where('status = ?', 'active')
            ->where('customer_id IS NOT NULL')
            ->where("license_id != ''")
            ->group(['customer_id', 'store_id'])
            ->having('COUNT(DISTINCT license_id) > 1');
        if ($onlyCustomer !== null) {
            $select->where('customer_id = ?', (int)$onlyCustomer);
        }
        return array_map(
            static fn (array $row): array => [$row['customer_id'], $row['store_id']],
            $connection->fetchAll($select)
        );
    }

    private function consolidateCustomer(
        int $customerId,
        int $storeId,
        bool $dryRun,
        OutputInterface $output
    ): void {
        $output->writeln(sprintf('Customer %d (store %d):', $customerId, $storeId));

        // Active rows, newest first, grouped by key.
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('status', 'active')
            ->addFieldToFilter('license_id', ['neq' => ''])
            ->setOrder('entity_id', 'DESC');
        /** @var array<string, License[]> $byKey */
        $byKey = [];
        foreach ($collection as $row) {
            $byKey[$row->getLicenseId()][] = $row;
        }

        // Ask sconce about each key; the target is the newest active unbounded one.
        $inspected = [];
        foreach (array_keys($byKey) as $licenseId) {
            try {
                $inspected[$licenseId] = $this->client->inspectLicense($licenseId, $storeId);
            } catch (ApiException $e) {
                $output->writeln(sprintf('  <comment>skip %s: %s</comment>', $licenseId, $e->getMessage()));
            }
        }
        $targetId = null;
        foreach (array_keys($byKey) as $licenseId) { // newest-first row order
            $i = $inspected[$licenseId] ?? null;
            if ($i !== null
                && ($i['status'] ?? '') === 'active'
                && ($i['bound']['until'] ?? null) === null
                && ($i['bound']['major'] ?? null) === null
            ) {
                $targetId = $licenseId;
                break;
            }
        }
        if ($targetId === null) {
            $output->writeln('  <comment>no active unbounded (account) key — skipped</comment>');
            return;
        }
        $target = $inspected[$targetId];
        $output->writeln(sprintf('  target: %s', $targetId));

        foreach ($byKey as $licenseId => $rows) {
            if ($licenseId === $targetId) {
                continue;
            }
            $status = $inspected[$licenseId]['status'] ?? 'unknown';
            if ($status === 'active') {
                if ($dryRun) {
                    $output->writeln(sprintf('  would merge %s -> %s (%d row(s))', $licenseId, $targetId, count($rows)));
                    continue;
                }
                try {
                    $merged = $this->client->mergeLicense($licenseId, $targetId, $storeId);
                } catch (ApiException $e) {
                    $output->writeln(sprintf('  <error>merge %s failed: %s</error>', $licenseId, $e->getMessage()));
                    continue;
                }
                if ($merged === null) {
                    $output->writeln(sprintf('  <comment>%s not mergeable (409) — left alone</comment>', $licenseId));
                    continue;
                }
                $target = $merged; // packages union grew
                $this->repointRows($rows, $targetId, $target, $output);
                $output->writeln(sprintf('  merged %s -> %s (%d row(s))', $licenseId, $targetId, count($rows)));
            } elseif ($status === 'revoked') {
                // Possibly merged operator-side already: re-point only rows the
                // target actually covers; the rest really are revoked.
                foreach ($rows as $row) {
                    $covered = array_diff($row->getPackages(), $target['packages'] ?? []) === [];
                    if ($dryRun) {
                        $output->writeln(sprintf(
                            '  would %s row %d (%s)',
                            $covered ? 're-point' : 'mark revoked',
                            (int)$row->getId(),
                            $row->getEdition()
                        ));
                        continue;
                    }
                    if ($covered) {
                        $this->repointRows([$row], $targetId, $target, $output);
                        $output->writeln(sprintf('  re-pointed row %d (%s) — target covers it', (int)$row->getId(), $row->getEdition()));
                    } else {
                        $row->setData('status', 'revoked');
                        $this->licenseResource->save($row);
                        $output->writeln(sprintf('  row %d (%s): source revoked, not covered — marked revoked', (int)$row->getId(), $row->getEdition()));
                    }
                }
            } else {
                $output->writeln(sprintf('  <comment>%s: status "%s" — left alone</comment>', $licenseId, $status));
            }
        }
    }

    /**
     * Point rows at the target key, storing its key material: re-encrypted from
     * the inspect/merge response when sconce recovered the plaintext, else
     * copied from a sibling row already on the target.
     *
     * @param License[] $rows
     * @param array<string, mixed> $target
     */
    private function repointRows(array $rows, string $targetId, array $target, OutputInterface $output): void
    {
        $encryptedKey = null;
        $plain = $target['key'] ?? null;
        if (is_string($plain) && $plain !== '') {
            $encryptedKey = $this->encryptor->encrypt($plain);
        } else {
            $sibling = $this->collectionFactory->create()
                ->addFieldToFilter('license_id', $targetId)
                ->addFieldToFilter('license_key', ['notnull' => true])
                ->setPageSize(1)
                ->getFirstItem();
            if ($sibling && $sibling->getId()) {
                $encryptedKey = $sibling->getData('license_key');
            } else {
                $output->writeln('  <comment>no key material for the target — rows re-pointed without a stored key</comment>');
            }
        }
        foreach ($rows as $row) {
            $row->setData('license_id', $targetId);
            $row->setData('license_key', $encryptedKey);
            $this->licenseResource->save($row);
        }
    }
}
