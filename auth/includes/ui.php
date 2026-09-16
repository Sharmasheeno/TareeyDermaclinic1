<?php
/**
 * auth/includes/ui.php
 * ---------------------------------------------------------------------
 * Shared presentation helpers for the Tarey Derma Clinic UI.
 *
 * This file intentionally does NOT declare(strict_types=1) so that the
 * loosely-typed call styles used across the nine existing pages keep
 * working. Every value is cast defensively before use.
 *
 * It is a *view* helper: it never touches the database, never reads
 * request state except where a resolver is explicitly called, and never
 * echoes anything by itself. All helpers return strings.
 * ---------------------------------------------------------------------
 */

if (!function_exists('tdc_ui_h')) {
    function tdc_ui_h($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('tdc_ui_is_date')) {
    function tdc_ui_is_date(?string $value): bool
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $parts = array_map('intval', explode('-', $value));
        return checkdate($parts[1], $parts[2], $parts[0]);
    }
}

if (!function_exists('tdc_icon')) {
    /**
     * Inline Lucide-style stroke icon. Keeps one icon language across the app.
     */
    function tdc_icon($name, $size = 18, $class = ''): string
    {
        static $paths = [
            'grid'         => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'logout'       => '<path d="M9 4H4v16h5M14 8l4 4-4 4M8 12h12"/>',
            'chart'        => '<path d="M3 3v18h18M7 17v-5M12 17V7M17 17V4"/>',
            'bell'         => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
            'building'     => '<path d="M4 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16"/><path d="M15 9h3a2 2 0 0 1 2 2v10"/><path d="M8 7h3M8 11h3M8 15h3M2 21h20"/>',
            'users'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user'         => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'shield'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'key'          => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2M17 6l2 2M14 9l2 2"/>',
            'stethoscope'  => '<path d="M4 3v6a5 5 0 0 0 10 0V3"/><path d="M4 3H2M14 3h2"/><path d="M9 14v2a5 5 0 0 0 10 0v-1"/><circle cx="19" cy="12" r="2"/>',
            'flask'        => '<path d="M9 3h6M10 3v6.5L5.5 18A2 2 0 0 0 7.2 21h9.6a2 2 0 0 0 1.7-3L14 9.5V3"/><path d="M7.5 15h9"/>',
            'pill'         => '<rect x="2.5" y="8.5" width="19" height="7" rx="3.5" transform="rotate(-45 12 12)"/><path d="M8.5 8.5 15.5 15.5"/>',
            'credit-card'  => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'wallet'       => '<path d="M20 12V8a2 2 0 0 0-2-2H5a2 2 0 0 1 0-4h13"/><path d="M3 6v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/><path d="M17 14h3"/>',
            'message'      => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.3-.6L3 21l1.8-5.2A8.4 8.4 0 0 1 12 3.1a8.4 8.4 0 0 1 9 8.4z"/>',
            'sliders'      => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h10M18 18h2"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="16" cy="18" r="2"/>',
            'scroll'       => '<path d="M6 3h11a2 2 0 0 1 2 2v13a3 3 0 0 0 3 3H8a3 3 0 0 1-3-3V4a1 1 0 0 1 1-1z"/><path d="M8 8h7M8 12h7M8 16h4"/>',
            'plus'         => '<path d="M12 5v14M5 12h14"/>',
            'pencil'       => '<path d="M17 3.5 20.5 7 8 19.5l-4.5 1 1-4.5z"/><path d="M15 5.5 18.5 9"/>',
            'trash'        => '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
            'ban'          => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
            'search'       => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'filter'       => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
            'download'     => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/>',
            'upload'       => '<path d="M12 21V9"/><path d="m7 14 5-5 5 5"/><path d="M4 4h16"/>',
            'more'         => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
            'x'            => '<path d="M6 6l12 12M18 6 6 18"/>',
            'eye'          => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            'printer'      => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 15h10v6H7z"/>',
            'phone'        => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
            'check'        => '<path d="M20 6 9 17l-5-5"/>',
            'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'calendar'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'file-text'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
            'refresh'      => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
            'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
            'chevron-left' => '<path d="m14 6-6 6 6 6"/>',
            'chevron-right'=> '<path d="m10 6 6 6-6 6"/>',
            'inbox'        => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14l2 7v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
            'link'         => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        ];
        $name = (string) $name;
        $size = max(10, min(64, (int) $size));
        $body = $paths[$name] ?? $paths['grid'];
        $classAttr = $class !== '' ? ' class="' . tdc_ui_h($class) . '"' : '';
        return '<svg' . $classAttr . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
    }
}

if (!function_exists('tdc_badge')) {
    /**
     * Semantic status badge. Tones: success, danger, warn, info, neutral, primary.
     */
    function tdc_badge($label, $tone = 'neutral', $extraClass = ''): string
    {
        $tone = strtolower(trim((string) $tone));
        $map = [
            'active' => 'success', 'approved' => 'success', 'completed' => 'success', 'paid' => 'success', 'linked' => 'success',
            'inactive' => 'neutral', 'cancelled' => 'danger', 'canceled' => 'danger', 'unpaid' => 'danger', 'not linked' => 'danger',
            'pending' => 'warn', 'pending payment' => 'warn', 'partial' => 'warn', 'waiting' => 'warn', 'unavailable' => 'warn',
            'in consultation' => 'info', 'in progress' => 'info', 'requested' => 'info',
            'available' => 'success', 'ready' => 'info', 'processing' => 'info', 'scheduled' => 'info',
            'low stock' => 'warn', 'expired' => 'danger', 'failed' => 'danger', 'draft' => 'neutral', 'unknown' => 'neutral',
        ];
        $lookup = strtolower(trim((string) $label));
        if (isset($map[$lookup])) $tone = $map[$lookup];
        $allowed = ['success', 'danger', 'warn', 'info', 'neutral', 'primary'];
        if (!in_array($tone, $allowed, true)) $tone = 'neutral';
        $classes = 'status-badge ' . $tone . ($extraClass !== '' ? ' ' . (string) $extraClass : '');
        return '<span class="' . tdc_ui_h($classes) . '">' . tdc_ui_h($label) . '</span>';
    }
}

if (!function_exists('tdc_toolbar_start')) {
    function tdc_toolbar_start($extraClass = ''): string
    {
        return '<div class="table-command-bar' . ($extraClass !== '' ? ' ' . tdc_ui_h($extraClass) : '') . '">';
    }
}

if (!function_exists('tdc_toolbar_end')) {
    function tdc_toolbar_end(): string
    {
        return '</div>';
    }
}

if (!function_exists('tdc_toolbar_spacer')) {
    function tdc_toolbar_spacer(): string
    {
        return '<span class="toolbar-spacer" aria-hidden="true"></span>';
    }
}

if (!function_exists('tdc_search_field')) {
    function tdc_search_field($name, $value, $placeholder, $target = ''): string
    {
        $targetAttr = $target !== '' ? ' data-table-filter="' . tdc_ui_h($target) . '"' : '';
        return '<label class="table-filter">' . tdc_icon('search', 15)
            . '<input type="search" name="' . tdc_ui_h($name) . '"' . $targetAttr
            . ' value="' . tdc_ui_h($value) . '" placeholder="' . tdc_ui_h($placeholder) . '"'
            . ' aria-label="' . tdc_ui_h($placeholder) . '" autocomplete="off">'
            . '</label>';
    }
}

if (!function_exists('tdc_date_range_resolve')) {
    /**
     * Reads and validates From/To dates from the query string.
     * Returns [from, to, error, active].
     */
    function tdc_date_range_resolve($fromKey = 'from_date', $toKey = 'to_date'): array
    {
        $rawFrom = trim((string) ($_GET[$fromKey] ?? ''));
        $rawTo = trim((string) ($_GET[$toKey] ?? ''));
        $from = tdc_ui_is_date($rawFrom) ? $rawFrom : '';
        $to = tdc_ui_is_date($rawTo) ? $rawTo : '';
        $error = '';
        if ($rawFrom !== '' && $from === '') {
            $error = 'From Date is not a valid date.';
        } elseif ($rawTo !== '' && $to === '') {
            $error = 'To Date is not a valid date.';
        } elseif ($from !== '' && $to !== '' && $from > $to) {
            $error = 'From Date must be on or before To Date.';
        }
        if ($error !== '') {
            return [$from, $to, $error, false];
        }
        return [$from, $to, '', ($from !== '' || $to !== '')];
    }
}

if (!function_exists('tdc_sql_date_range')) {
    /**
     * Builds a validated date-range WHERE fragment for a trusted column name.
     * The column is developer-supplied; anything unusual is rejected.
     */
    function tdc_sql_date_range($column, $from, $to, array &$params, $dateOnly = false): array
    {
        $column = (string) $column;
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) return [];
        $clauses = [];
        if ($from !== '' && $from !== null && tdc_ui_is_date((string) $from)) {
            $clauses[] = $column . ' >= ?';
            $params[] = $dateOnly ? (string) $from : ((string) $from) . ' 00:00:00';
        }
        if ($to !== '' && $to !== null && tdc_ui_is_date((string) $to)) {
            $clauses[] = $column . ' <= ?';
            $params[] = $dateOnly ? (string) $to : ((string) $to) . ' 23:59:59';
        }
        return $clauses;
    }
}

if (!function_exists('tdc_date_range')) {
    /**
     * Server-driven From/To filter. Works without JavaScript and preserves
     * any extra filters supplied through $options['preserve'].
     */
    function tdc_date_range(array $options = []): string
    {
        $from        = (string) ($options['from'] ?? '');
        $to          = (string) ($options['to'] ?? '');
        $error       = (string) ($options['error'] ?? '');
        $preserve    = is_array($options['preserve'] ?? null) ? $options['preserve'] : [];
        $labelFrom   = (string) ($options['label_from'] ?? 'From Date');
        $labelTo     = (string) ($options['label_to'] ?? 'To Date');
        $fromKey     = (string) ($options['from_key'] ?? 'from_date');
        $toKey       = (string) ($options['to_key'] ?? 'to_date');
        $action      = (string) ($options['action'] ?? '');
        $id          = (string) ($options['id'] ?? 'dateRange');
        $showClear   = ($from !== '' || $to !== '');
        $clearQuery  = array_filter($preserve, static fn($v): bool => $v !== '' && $v !== null);
        $clearUrl    = $action !== '' ? $action : basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        if ($clearQuery) $clearUrl .= '?' . http_build_query($clearQuery);
        $html = '<form class="date-range" id="' . tdc_ui_h($id) . '" method="get"'
            . ($action !== '' ? ' action="' . tdc_ui_h($action) . '"' : '') . ' data-date-range>';
        foreach ($preserve as $key => $value) {
            if ($value === '' || $value === null) continue;
            $html .= '<input type="hidden" name="' . tdc_ui_h($key) . '" value="' . tdc_ui_h($value) . '">';
        }
        $html .= '<div class="date-range-field"><label for="' . tdc_ui_h($id) . '_from">' . tdc_ui_h($labelFrom) . '</label>'
            . '<input type="date" id="' . tdc_ui_h($id) . '_from" name="' . tdc_ui_h($fromKey) . '" value="' . tdc_ui_h($from) . '"></div>'
            . '<div class="date-range-field"><label for="' . tdc_ui_h($id) . '_to">' . tdc_ui_h($labelTo) . '</label>'
            . '<input type="date" id="' . tdc_ui_h($id) . '_to" name="' . tdc_ui_h($toKey) . '" value="' . tdc_ui_h($to) . '"></div>'
            . '<div class="date-range-actions">'
            . '<button type="submit" class="btn btn-primary btn-sm">' . tdc_icon('filter', 14) . '<span>Apply</span></button>';
        if ($showClear) {
            $html .= '<a class="btn btn-secondary btn-sm" href="' . tdc_ui_h($clearUrl) . '">' . tdc_icon('refresh', 14) . '<span>Clear</span></a>';
        }
        $html .= '</div>';
        $html .= '<p class="field-error date-range-error"' . ($error === '' ? ' hidden' : '') . '>' . tdc_ui_h($error) . '</p>';
        $html .= '</form>';
        return $html;
    }
}

if (!function_exists('tdc_empty_state')) {
    function tdc_empty_state($icon, $title, $message = '', $actionHtml = '', $colspan = 0): string
    {
        if ($colspan > 0) {
            return '<tr class="empty-row"><td colspan="' . (int) $colspan . '">'
                . '<div class="empty-state">' . tdc_icon($icon, 30)
                . '<strong>' . tdc_ui_h($title) . '</strong>'
                . ($message !== '' ? '<span>' . tdc_ui_h($message) . '</span>' : '')
                . ($actionHtml !== '' ? '<div class="empty-state-action">' . $actionHtml . '</div>' : '')
                . '</div></td></tr>';
        }
        return '<div class="empty-state">' . tdc_icon($icon, 30)
            . '<strong>' . tdc_ui_h($title) . '</strong>'
            . ($message !== '' ? '<span>' . tdc_ui_h($message) . '</span>' : '')
            . ($actionHtml !== '' ? '<div class="empty-state-action">' . $actionHtml . '</div>' : '')
            . '</div>';
    }
}

if (!function_exists('tdc_pager')) {
    function tdc_pager($page, $perPage, $total, array $preserve = [], $window = 2): string
    {
        $page    = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $total   = max(0, (int) $total);
        $pages   = (int) max(1, (int) ceil($total / $perPage));
        if ($pages < 2) return '';
        $page   = min($page, $pages);
        $link   = static function (int $target) use ($preserve): string {
            $query = $preserve;
            $query['page'] = $target;
            return '?' . http_build_query($query);
        };
        $html = '<nav class="table-pager" aria-label="Pagination">';
        $html .= '<span class="pager-summary">Page ' . $page . ' of ' . $pages . '</span>';
        $html .= '<div class="pager-links">';
        $html .= '<a class="pager-btn' . ($page <= 1 ? ' disabled' : '') . '" href="' . tdc_ui_h($link(max(1, $page - 1))) . '"'
            . ($page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '') . '>' . tdc_icon('chevron-left', 14) . '</a>';
        for ($i = max(1, $page - $window); $i <= min($pages, $page + $window); $i++) {
            $html .= '<a class="pager-btn' . ($i === $page ? ' active' : '') . '" href="' . tdc_ui_h($link($i)) . '"'
                . ($i === $page ? ' aria-current="page"' : '') . '>' . $i . '</a>';
        }
        $html .= '<a class="pager-btn' . ($page >= $pages ? ' disabled' : '') . '" href="' . tdc_ui_h($link(min($pages, $page + 1))) . '"'
            . ($page >= $pages ? ' aria-disabled="true" tabindex="-1"' : '') . '>' . tdc_icon('chevron-right', 14) . '</a>';
        $html .= '</div></nav>';
        return $html;
    }
}

if (!function_exists('tdc_action_menu')) {
    /**
     * $items: [['label' => 'Edit', 'icon' => 'pencil', 'href' => '...'] or
     *          ['label' => 'Deactivate', 'icon' => 'ban', 'attrs' => '...', 'danger' => true]]
     */
    function tdc_action_menu(array $items, $label = 'Actions'): string
    {
        if (!$items) return '';
        $html = '<div class="action-menu"><button type="button" class="action-menu-trigger" aria-haspopup="true" aria-expanded="false" aria-label="' . tdc_ui_h($label) . '">'
            . tdc_icon('more', 16) . '</button><div class="action-menu-list" role="menu">';
        foreach ($items as $item) {
            $text  = (string) ($item['label'] ?? '');
            $icon  = (string) ($item['icon'] ?? '');
            $attrs = (string) ($item['attrs'] ?? '');
            $class = 'action-menu-item' . (!empty($item['danger']) ? ' danger' : '');
            $inner = ($icon !== '' ? tdc_icon($icon, 14) : '') . '<span>' . tdc_ui_h($text) . '</span>';
            if (!empty($item['href'])) {
                $html .= '<a class="' . $class . '" role="menuitem" href="' . tdc_ui_h($item['href']) . '" ' . $attrs . '>' . $inner . '</a>';
            } else {
                $html .= '<button type="button" class="' . $class . '" role="menuitem" ' . $attrs . '>' . $inner . '</button>';
            }
        }
        return $html . '</div></div>';
    }
}

if (!function_exists('tdc_icon_button')) {
    function tdc_icon_button($icon, $label, $attrs = '', $tone = ''): string
    {
        $class = 'icon-action' . ($tone !== '' ? ' ' . (string) $tone : '');
        return '<button type="button" class="' . tdc_ui_h($class) . '" title="' . tdc_ui_h($label) . '" aria-label="' . tdc_ui_h($label) . '" ' . $attrs . '>'
            . tdc_icon($icon, 15) . '</button>';
    }
}

if (!function_exists('tdc_link_button')) {
    function tdc_link_button($href, $icon, $label, $tone = 'secondary', $attrs = ''): string
    {
        return '<a class="btn btn-' . tdc_ui_h($tone) . ' btn-sm" href="' . tdc_ui_h($href) . '" ' . $attrs . '>'
            . ($icon !== '' ? tdc_icon($icon, 14) : '') . '<span>' . tdc_ui_h($label) . '</span></a>';
    }
}

if (!function_exists('tdc_export_buttons')) {
    function tdc_export_buttons(array $links, $withPrint = true): string
    {
        $html = '<div class="export-group">';
        if (!empty($links['csv'])) {
            $html .= '<a class="btn btn-info btn-sm" href="' . tdc_ui_h($links['csv']) . '">' . tdc_icon('download', 14) . '<span>Export CSV</span></a>';
        }
        if (!empty($links['excel'])) {
            $html .= '<a class="btn btn-info btn-sm" href="' . tdc_ui_h($links['excel']) . '">' . tdc_icon('file-text', 14) . '<span>Excel</span></a>';
        }
        if (!empty($links['pdf'])) {
            $html .= '<a class="btn btn-info btn-sm" href="' . tdc_ui_h($links['pdf']) . '">' . tdc_icon('file-text', 14) . '<span>PDF</span></a>';
        }
        if ($withPrint) {
            $html .= '<button type="button" class="btn btn-info btn-sm" data-print-page>' . tdc_icon('printer', 14) . '<span>Print</span></button>';
        }
        return $html . '</div>';
    }
}

function tdc_navigation_icon(string $page): string
{
    $icons = ['home.php'=>'grid','reception.php'=>'bell','patients.php'=>'users','doctors.php'=>'stethoscope','laboratory.php'=>'flask','pharmacy.php'=>'pill','accounting.php'=>'wallet','reports.php'=>'chart','setup.php'=>'sliders'];
    return tdc_icon($icons[$page] ?? 'grid',18);
}
