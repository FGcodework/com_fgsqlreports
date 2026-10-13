<?php
/**
 * @package     COM_FGSQLREPORTS
 * @copyright   Copyright (C) Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\Fgsqlreports\Administrator\Model;

defined('_JEXEC') or die;

use FG\Component\Fgsqlreports\Administrator\Helper\ConnectionHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;

class ReportModel extends AdminModel
{
    public $typeAlias = 'com_fgsqlreports.report';

    public function getTable($type = 'Report', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_fgsqlreports.edit.report.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_fgsqlreports.report',
            'report',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        if (empty($form)) {
            return false;
        }

        // Status and access level are gated by core.edit.state. Without it
        // the fields are shown read-only and, more importantly, stripped
        // from the submitted data ("unset" filter), so a tampered POST
        // can't change them either. save() enforces the same server-side.
        if (!$this->canChangeState()) {
            foreach (['published', 'access'] as $name) {
                $form->setFieldAttribute($name, 'disabled', 'true');
                $form->setFieldAttribute($name, 'filter', 'unset');
            }
        }

        return $form;
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item !== false && empty($item->id)) {
            $item->cache_ttl = (int) \Joomla\CMS\Component\ComponentHelper::getParams('com_fgsqlreports')
                ->get('default_cache_ttl', 0);
        }

        return $item;
    }

    /**
     * AdminModel::save() calls this hook but its own base implementation is
     * an empty stub ("Derived class will provide its own implementation if
     * required.") - without overriding it, created/created_by/modified/
     * modified_by/ordering are never actually populated.
     */
    protected function prepareTable($table): void
    {
        $date   = Factory::getDate()->toSql();
        $userId = (int) Factory::getApplication()->getIdentity()->id;

        if (empty($table->id)) {
            $table->created    = $date;
            $table->created_by = $userId;
            $table->ordering   = $table->getNextOrder();
        }

        $table->modified    = $date;
        $table->modified_by = $userId;
    }

    /**
     * Execute a report's SQL script (or an arbitrary script passed for a
     * not-yet-saved report) and return a limited preview result set.
     *
     * @return array{rows: array, truncated: bool}
     * @throws Exception on connection or query failure - caller shows the message.
     */
    public function preview(string $sqlScript): array
    {
        $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_fgsqlreports');
        $limit  = (int) $params->get('preview_row_limit', 100);

        return ConnectionHelper::runScript($sqlScript, $limit);
    }

    /**
     * Whether the current user may change a report's status (published) and
     * access level - Joomla's core.edit.state on the component.
     */
    protected function canChangeState(): bool
    {
        return Factory::getApplication()->getIdentity()->authorise('core.edit.state', 'com_fgsqlreports');
    }

    public function save($data)
    {
        $user = Factory::getApplication()->getIdentity();
        $id   = (int) ($data['id'] ?? 0);

        // Server-side ACL enforcement, independent of what the form rendered
        // or what was posted: without core.edit.state, published/access can
        // never be changed. An existing report keeps its stored values; a new
        // one is created unpublished with the default (Registered) access.
        if (!$this->canChangeState()) {
            unset($data['published'], $data['access']);

            if ($id === 0) {
                $data['published'] = 0;
                $data['access']    = 2;
            }
        }

        // A published report's SQL runs on the front end as soon as it is
        // saved, so changing it is as good as running ad-hoc SQL. Keep that
        // gated by the same fgsqlreports.execute action as the Preview.
        if ($id > 0 && array_key_exists('sql_script', $data) && !$user->authorise('fgsqlreports.execute', 'com_fgsqlreports')) {
            $existing = $this->getTable();

            if ($existing->load($id)
                && (int) $existing->published === 1
                && trim((string) $existing->sql_script) !== trim((string) $data['sql_script'])
            ) {
                $this->setError(\Joomla\CMS\Language\Text::_('COM_FGSQLREPORTS_ERROR_SQL_CHANGE_FORBIDDEN'));

                return false;
            }
        }

        $result = parent::save($data);

        if ($result) {
            // The front-end cache key already changes with the SQL/modified
            // content (see Site\Model\ReportModel::runWithCache()), but also
            // clear the whole group here so old, now-unreachable cache
            // entries don't just sit on disk until they naturally expire.
            try {
                Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)
                    ->createCacheController('callback', ['defaultgroup' => 'com_fgsqlreports'])
                    ->clean('com_fgsqlreports');
            } catch (Exception $e) {
                // Non-fatal - the content-hash cache key already prevents
                // stale results from being served either way.
            }
        }

        return $result;
    }
}
