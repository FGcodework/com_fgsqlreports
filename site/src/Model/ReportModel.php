<?php
/**
 * @package     COM_FGSQLREPORTS
 * @copyright   Copyright (C) Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Component\Fgsqlreports\Site\Model;

defined('_JEXEC') or die;

use FG\Component\Fgsqlreports\Administrator\Helper\ConnectionHelper;
use Exception;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\ItemModel;
use Joomla\Database\ParameterType;

class ReportModel extends ItemModel
{
    /**
     * Result: ['title', 'description', 'columns', 'rows' (current page only),
     * 'error', 'total', 'limit', 'limitstart', 'sort_column', 'sort_dir',
     * 'truncated']
     *
     * @throws \Joomla\CMS\Router\Exception\RouteNotFoundException when the
     *         report doesn't exist, isn't published, or isn't visible to the
     *         current user's access level - a proper 404, not a 200 with an
     *         error message on the page. The message is identical for all
     *         three cases so the response can't be used to enumerate which
     *         one it was.
     */
    public function getReportData(): array
    {
        $item = $this->getItem();

        if (!$item) {
            throw new \Joomla\CMS\Router\Exception\RouteNotFoundException(
                Text::_('COM_FGSQLREPORTS_ERROR_REPORT_NOT_FOUND')
            );
        }

        $limit = (int) $item->page_limit > 0
            ? (int) $item->page_limit
            : (int) ComponentHelper::getParams('com_fgsqlreports')->get('frontend_page_limit', 50);
        $limitstart = Factory::getApplication()->getInput()->getUint('limitstart', 0);
        $sortingEnabled = (bool) $item->enable_sorting;
        $sortColumn = $sortingEnabled ? Factory::getApplication()->getInput()->getString('sort', '') : '';
        $sortDir = strtolower(Factory::getApplication()->getInput()->getString('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $result = [
            'title'           => $item->title,
            'description'     => $item->description,
            'columns'         => [],
            'rows'            => [],
            'error'           => null,
            'total'           => 0,
            'limit'           => $limit,
            'limitstart'      => $limitstart,
            'stack_mobile'    => (bool) $item->stack_mobile,
            'table_css_class' => $this->sanitizeCssClasses((string) $item->table_css_class),
            'sort_column'     => '',
            'sort_dir'        => $sortDir,
            'sorting_enabled' => $sortingEnabled,
            'truncated'       => false,
        ];

        try {
            $capped = $this->runWithCache($item);
            $allRows = $capped['rows'];
            $columns = $allRows ? array_keys(reset($allRows)) : [];

            // Only sort by a column that actually exists in this result set -
            // the value comes straight from the query string, so it must be
            // whitelisted against the real columns, never used as-is.
            if ($sortColumn !== '' && \in_array($sortColumn, $columns, true)) {
                $this->sortRows($allRows, $sortColumn, $sortDir);
                $result['sort_column'] = $sortColumn;
            }

            $total = \count($allRows);

            if ($limitstart >= $total && $total > 0) {
                $limitstart = $limit > 0 ? (int) (floor(($total - 1) / $limit) * $limit) : 0;
            }

            $pageRows = $limit > 0 ? \array_slice($allRows, $limitstart, $limit) : $allRows;

            $result['rows'] = $pageRows;
            $result['columns'] = $columns;
            $result['total'] = $total;
            $result['limitstart'] = $limitstart;
            $result['truncated'] = $capped['truncated'];
        } catch (Exception $e) {
            Log::add(
                sprintf('com_fgsqlreports report #%d execution failed: %s', $item->id, $e->getMessage()),
                Log::ERROR,
                'com_fgsqlreports'
            );

            $user = Factory::getApplication()->getIdentity();
            $result['error'] = $user->authorise('core.admin', 'com_fgsqlreports')
                ? $e->getMessage()
                : Text::_('COM_FGSQLREPORTS_ERROR_REPORT_EXECUTION_GENERIC');
        }

        return $result;
    }

    public function getItem($pk = null)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $user = Factory::getApplication()->getIdentity();

        $id = $pk ?? (int) $this->getState('report.id');
        $alias = (string) $this->getState('report.alias', '');

        $query->select('*')->from($db->quoteName('#__fgsqlreports_reports'))
            ->where($db->quoteName('published') . ' = 1');

        if ($id > 0) {
            $query->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
        } elseif ($alias !== '') {
            $query->where($db->quoteName('alias') . ' = :alias')
                ->bind(':alias', $alias);
        } else {
            return null;
        }

        $db->setQuery($query);
        $item = $db->loadObject();

        if (!$item) {
            return null;
        }

        if (!\in_array((int) $item->access, $user->getAuthorisedViewLevels(), true)) {
            return null;
        }

        return $item;
    }

    private function sortRows(array &$rows, string $column, string $dir): void
    {
        $collator = $this->getCollator();

        usort($rows, function (array $a, array $b) use ($column, $dir, $collator) {
            $valA = $a[$column] ?? null;
            $valB = $b[$column] ?? null;

            // Nulls always sort last, regardless of direction.
            if ($valA === null && $valB === null) {
                return 0;
            }

            if ($valA === null) {
                return 1;
            }

            if ($valB === null) {
                return -1;
            }

            if (is_numeric($valA) && is_numeric($valB)) {
                $cmp = $valA <=> $valB;
            } elseif ($collator !== null) {
                // Locale-correct comparison (e.g. Slovak ľ/š/č/ť/ž/ý sort in
                // their proper alphabetical position, not by raw byte value).
                $cmp = $collator->compare((string) $valA, (string) $valB);
            } else {
                $cmp = strnatcasecmp((string) $valA, (string) $valB);
            }

            return $dir === 'desc' ? -$cmp : $cmp;
        });
    }

    /**
     * A Collator for the site's language gives correct alphabetical
     * ordering for diacritics (Slovak ľščťžýáíé and similar). The intl
     * extension isn't guaranteed to be installed, so this degrades to null
     * (caller falls back to strnatcasecmp) when it isn't available.
     */
    private function getCollator(): ?\Collator
    {
        if (!class_exists(\Collator::class)) {
            return null;
        }

        try {
            return new \Collator(Factory::getApplication()->getLanguage()->getTag());
        } catch (\Exception $e) {
            return null;
        }
    }

    private function sanitizeCssClasses(string $classes): string
    {
        $classes = preg_replace('/[^A-Za-z0-9_\- ]/', '', $classes) ?? '';

        return trim(preg_replace('/\s+/', ' ', $classes) ?? '');
    }

    private function runWithCache(object $item): array
    {
        $params  = ComponentHelper::getParams('com_fgsqlreports');
        $maxRows = (int) $params->get('max_rows', 10000);
        $ttl     = (int) $item->cache_ttl;

        if ($ttl <= 0) {
            return ConnectionHelper::runScript($item->sql_script, $maxRows);
        }

        $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('callback', ['defaultgroup' => 'com_fgsqlreports']);
        $cache->setLifeTime($ttl);
        $cache->setCaching(true);

        // Identifies which server/database this report's cached result
        // actually came from - if the admin repoints Options at a
        // different connection, the key changes and the old (now
        // possibly-wrong) cached result is never read again, instead of
        // staying visible until it naturally expires.
        $connectionSignature = implode('|', [
            $params->get('dbhost', ''),
            $params->get('dbport', ''),
            $params->get('dbname', ''),
            $params->get('dbuser', ''),
            $params->get('dbapplicationintent', 0),
        ]);

        $cacheId = 'report_' . (int) $item->id . '_'
            . hash('sha256', $item->sql_script . '|' . $item->modified . '|' . $maxRows . '|' . $connectionSignature);

        $result = $cache->get(
            [ConnectionHelper::class, 'runScript'],
            [$item->sql_script, $maxRows],
            $cacheId
        );

        return \is_array($result) && isset($result['rows'], $result['truncated'])
            ? $result
            : ['rows' => [], 'truncated' => false];
    }
}
