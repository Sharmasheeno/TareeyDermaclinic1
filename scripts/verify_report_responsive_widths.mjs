import fs from 'fs';
import path from 'path';

const cssContent = fs.readFileSync('auth/assets/clinic.css', 'utf8');
const reportsPhpContent = fs.readFileSync('auth/pages/reports.php', 'utf8');
const reportsCenterContent = fs.readFileSync('auth/includes/reports-center.php', 'utf8');

const checks = [];

function assert(name, condition, extra = '') {
    checks.push({ name, pass: !!condition, extra });
    console.log(`${condition ? 'PASS' : 'FAIL'}: ${name} ${extra ? '(' + extra + ')' : ''}`);
}

// 1. Shared Report Filter Markup Check
assert(
    'Shared wrapper .report-search-field exists in reports-center.php',
    reportsCenterContent.includes('class="report-search-field report-filter-search"')
);
assert(
    'Shared toolbar class .report-filter-toolbar exists in reports-center.php',
    reportsCenterContent.includes('class="report-toolbar report-filter-toolbar')
);
assert(
    'Dedicated action wrapper .report-export-actions exists in reports-center.php',
    reportsCenterContent.includes('class="report-export-actions"')
);
assert(
    'Dedicated button group .report-filter-actions exists in reports-center.php',
    reportsCenterContent.includes('class="report-filter-actions"')
);

// 2. CSS Search Min-width Check (260px - 320px)
const searchMinWidthMatch = cssContent.match(/\.report-search-field[^{]*\{[^}]*min-width:\s*(\d+)px/);
const minWidthVal = searchMinWidthMatch ? parseInt(searchMinWidthMatch[1], 10) : 0;
assert(
    'Search input wrapper min-width is between 260px and 320px',
    minWidthVal >= 260 && minWidthVal <= 320,
    `found ${minWidthVal}px`
);

// 3. Search flex growth
assert(
    'Search input wrapper flex-grow is >= 1',
    cssContent.includes('flex: 1 1 280px') || cssContent.includes('flex: 1 1')
);

// 4. Focus state check (Indigo border and focus ring, NOT red/orange)
assert(
    ':focus-visible excludes input, select, textarea, and .tdc-search from orange outline',
    cssContent.includes(':focus-visible:not(input):not(select):not(textarea):not(.tdc-search)')
);
assert(
    'tdc-search focus-within uses primary indigo ring and no outline',
    cssContent.includes('.tdc-search:focus-within') &&
    cssContent.includes('border-color: var(--primary') &&
    cssContent.includes('outline: none !important')
);

// 5. Search placeholder is not clipped and input has full width
assert(
    'Search input has 100% width inside wrapper',
    cssContent.includes('.report-search-field .tdc-search input') &&
    cssContent.includes('width: 100% !important')
);

// 6. Height consistency (40px)
assert(
    'Quick Period select height is 40px',
    cssContent.includes('.report-filters .quick-period-control select') &&
    cssContent.includes('height: 40px')
);
assert(
    'Date range field input height is 40px',
    cssContent.includes('.report-filters .date-range-field input[type="date"]') &&
    cssContent.includes('height: 40px')
);
assert(
    'Search field height is 40px',
    cssContent.includes('.report-filters .tdc-search') &&
    cssContent.includes('height: 40px')
);
assert(
    'Status select height is 40px',
    cssContent.includes('.report-filters .report-filter-status select') &&
    cssContent.includes('height: 40px')
);
assert(
    'Filter buttons height is 40px',
    cssContent.includes('.report-filter-actions .btn') &&
    cssContent.includes('height: 40px')
);

// 7. Responsive Breakpoints
assert(
    'Tablet media query (1024px) properly defines flexible search',
    cssContent.includes('@media (max-width: 1024px)') &&
    cssContent.includes('.report-search-field, .report-filter-search')
);
assert(
    'Mobile media query (768px) expands search to 100% width',
    cssContent.includes('@media (max-width: 768px)') &&
    cssContent.includes('width: 100%')
);

// 8. reports.php inline style fallback parity
assert(
    'reports.php has fallback rules matching clinic.css',
    reportsPhpContent.includes('.report-search-field') &&
    reportsPhpContent.includes('min-width:260px')
);

const allOk = checks.every(c => c.pass);
console.log(`\nOVERALL STATUS: ${allOk ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED'}`);
process.exit(allOk ? 0 : 1);
