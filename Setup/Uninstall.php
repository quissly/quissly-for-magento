<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Leave no trace on `module:uninstall` (SPEC Stage 7).
 *
 * Removes the module's own tables, its flags and every config row it wrote -
     * INCLUDING the encrypted credentials, which must not survive a removal.
 *
 * Deliberately does NOT touch the merchant's catalog: nothing we store outside
 * these names belongs to us.
 *
 * Caveat worth documenting for merchants: a module removed by deleting files
 * (rather than `bin/magento module:uninstall`) never runs this class, and its
 * rows stay behind. That is Magento's behaviour, not something we can fix.
 */
class Uninstall implements UninstallInterface
{
    /** Tables declared in etc/db_schema.xml. */
    private const TABLES = ['quissly_sync_queue', 'quissly_product_state'];

    /** Flag prefixes written by the sync, gate, health and capability models. */
    private const FLAG_PREFIXES = [
        'quissly_first_sync_complete_w',
        'quissly_sync_progress_w',
        'quissly_health_w',
        'quissly_media_gate_',
        'quissly_showcase_w',
    ];

    /**
     * Drop everything this module created.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        foreach (self::TABLES as $table) {
            $name = $setup->getTable($table);
            if ($connection->isTableExists($name)) {
                $connection->dropTable($name);
            }
        }

        // Credentials live here too: an uninstall must not leave an encrypted
        // bearer token or private key behind in core_config_data.
        $connection->delete(
            $setup->getTable('core_config_data'),
            ['path LIKE ?' => 'quissly/%']
        );

        $flagTable = $setup->getTable('flag');
        foreach (self::FLAG_PREFIXES as $prefix) {
            $connection->delete($flagTable, ['flag_code LIKE ?' => $prefix . '%']);
        }

        $setup->endSetup();
    }
}
