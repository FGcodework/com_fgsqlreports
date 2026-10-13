<?php
/**
 * @package     COM_FGSQLREPORTS
 * @copyright   Copyright (C) Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;

/**
 * Classic (non-namespaced, duck-typed) installer script class - deliberately
 * not implementing Joomla\CMS\Installer\InstallerScriptInterface, since that
 * interface's availability/behaviour has been less reliably consistent
 * across Joomla versions in our experience than the plain method-name
 * convention Joomla has supported unchanged since 3.x.
 */
class Com_fgsqlreportsInstallerScript
{
    /**
     * Non-blocking check for the pdo_sqlsrv extension before install/update.
     * The component still installs fine without it - it's just useless
     * until pdo_sqlsrv is available - so this only warns, never fails.
     */
    public function preflight($type, $parent)
    {
        if (!\extension_loaded('pdo_sqlsrv')) {
            // Literal text, not Text::_() - preflight runs before this
            // component's own language files exist on disk yet, so a
            // language key would just show up untranslated.
            Factory::getApplication()->enqueueMessage(
                'FG SQL Reports: the PHP pdo_sqlsrv extension is not installed/enabled. '
                . 'The component will install fine, but reports won\'t run until you install '
                . 'the Microsoft Drivers for PHP for SQL Server and enable pdo_sqlsrv.',
                'warning'
            );
        }

        return true;
    }

    /**
     * On update, re-encrypts a legacy plaintext MS SQL connection password
     * left over from before password encryption was introduced (1.3.0).
     * Never blocks the update - any failure here is swallowed and simply
     * leaves the password as it was, to be picked up again on the next
     * update.
     */
    public function postflight($type, $parent)
    {
        try {
            if ($type === 'update') {
                $this->reencryptLegacyPassword();
            }

            // Also on 'update': reinstalling the same ZIP is how a failed
            // migration gets retried (guarded by the "migrated" flag).
            if ($type === 'install' || $type === 'update') {
                $this->migrateFromLegacyComponent();
            }
        } catch (\Throwable $e) {
            // Never let a migration helper break the install/update itself.
        }

        return true;
    }

    /**
     * Migration from the pre-2.0.0 name of this component (com_fgreports,
     * table #__fgreports_reports). Copies the old reports and the old Options
     * (incl. the already-encrypted connection password) across. Never touches
     * or deletes the old data. Menu items and ACL rules are NOT migrated.
     *
     * - Columns are matched by name (intersection of old and new table), so an
     *   old table from any 1.x version works; columns missing in the old table
     *   (e.g. enable_sorting) simply get their default value.
     * - Reports are only copied while the new table is empty; the reports copy
     *   runs in a transaction, so a failure leaves no partial copy.
     * - A flag in the component params marks a completed migration. Until it
     *   is set, running again (reinstalling the same ZIP counts as an update)
     *   retries, so a failed attempt can be repeated.
     * - Failures are written to the Joomla log (category com_fgsqlreports)
     *   and shown to the admin as a warning.
     */
    private function migrateFromLegacyComponent(): void
    {
        $app = Factory::getApplication();

        try {
            $db     = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $tables = array_map('strtolower', $db->getTableList());
            $old    = strtolower($db->getPrefix() . 'fgreports_reports');
            $new    = strtolower($db->getPrefix() . 'fgsqlreports_reports');

            if (!\in_array($old, $tables, true) || !\in_array($new, $tables, true)) {
                return; // Nothing to migrate from (fresh install without the old component).
            }

            $extQuery = static function ($db, string $element) {
                return $db->getQuery(true)
                    ->select($db->quoteName(['extension_id', 'params']))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote($element));
            };

            $newExt = $db->setQuery($extQuery($db, 'com_fgsqlreports'))->loadAssoc();
            $newParams = json_decode((string) ($newExt['params'] ?? ''), true) ?: [];

            if (!empty($newParams['migrated_from_fgreports'])) {
                return; // Already migrated.
            }

            $copied = 0;
            $count  = (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__fgsqlreports_reports'))
            )->loadResult();

            if ($count === 0) {
                $common = array_values(array_intersect(
                    array_keys($db->getTableColumns('#__fgreports_reports', false)),
                    array_keys($db->getTableColumns('#__fgsqlreports_reports', false))
                ));

                if ($common) {
                    $cols = implode(', ', array_map([$db, 'quoteName'], $common));

                    $db->transactionStart();

                    try {
                        $db->setQuery(
                            'INSERT INTO ' . $db->quoteName('#__fgsqlreports_reports') . ' (' . $cols . ')'
                            . ' SELECT ' . $cols . ' FROM ' . $db->quoteName('#__fgreports_reports')
                        )->execute();
                        $copied = (int) $db->getAffectedRows();
                        $db->transactionCommit();
                    } catch (\Throwable $e) {
                        $db->transactionRollback();
                        throw $e;
                    }
                }
            }

            // Options of the old component (connection settings etc.).
            $oldExt    = $db->setQuery($extQuery($db, 'com_fgreports'))->loadAssoc();
            $oldParams = json_decode((string) ($oldExt['params'] ?? ''), true) ?: [];

            $merged = array_merge($newParams, $oldParams);
            $merged['migrated_from_fgreports'] = 1;

            // Never carry a plaintext password over: if the old Options still
            // hold one (saved before encryption existed), encrypt it now
            // instead of waiting for someone to open and save Options. If
            // encryption fails, this throws before anything is stored, so the
            // plaintext value never lands in the new component's params.
            $pass = (string) ($merged['dbpass'] ?? '');

            if ($pass !== '' && !str_starts_with($pass, 'fgenc:v1:')) {
                $helperClass = \FG\Component\Fgsqlreports\Administrator\Helper\CryptoHelper::class;

                if (!class_exists($helperClass)) {
                    // The namespace map isn't necessarily loaded yet during
                    // the install request itself - load the file directly.
                    require_once JPATH_ADMINISTRATOR . '/components/com_fgsqlreports/src/Helper/CryptoHelper.php';
                }

                $merged['dbpass'] = $helperClass::encrypt($pass);
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($merged)))
                    ->where($db->quoteName('extension_id') . ' = ' . (int) ($newExt['extension_id'] ?? 0))
            )->execute();

            $app->enqueueMessage(
                'FG SQL Reports: reports (' . $copied . ') and Options were copied from the old com_fgreports component. '
                . 'You can now uninstall com_fgreports and re-create its menu items using the new component.',
                'message'
            );
        } catch (\Throwable $e) {
            try {
                \Joomla\CMS\Log\Log::add(
                    'Migration from com_fgreports failed: ' . $e->getMessage(),
                    \Joomla\CMS\Log\Log::ERROR,
                    'com_fgsqlreports'
                );
            } catch (\Throwable $ignored) {
                // Logging must never break the install either.
            }

            $app->enqueueMessage(
                'FG SQL Reports: the automatic migration from the old com_fgreports component FAILED ('
                . $e->getMessage() . '). Nothing was changed in the old component. '
                . 'Re-install this package to retry, or re-create your reports manually.',
                'warning'
            );
        }
    }

    private function reencryptLegacyPassword(): void
    {
        if (!class_exists(\FG\Component\Fgsqlreports\Administrator\Helper\CryptoHelper::class)) {
            return;
        }

        /** @var Table $table */
        $table = Table::getInstance('extension');

        if (!$table->load(['type' => 'component', 'element' => 'com_fgsqlreports'])) {
            return;
        }

        $params = json_decode((string) $table->params, true) ?: [];
        $current = (string) ($params['dbpass'] ?? '');

        if ($current === '' || str_starts_with($current, 'fgenc:v1:')) {
            // Nothing to migrate - empty, or already encrypted.
            return;
        }

        $params['dbpass'] = \FG\Component\Fgsqlreports\Administrator\Helper\CryptoHelper::encrypt($current);
        $table->params = json_encode($params);
        $table->store();
    }
}
