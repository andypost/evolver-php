// Advanced Filter State
    const filterState = {
        severity: new Set(),
        category: new Set(),
        fixability: new Set(),
        change_type: new Set(),
        file_pattern: ''
    };

    let currentView = 'flat';

    // Initialize filters from URL params
    function initializeFilters() {
        const urlParams = new URLSearchParams(window.location.search);

        // Severity filters
        const severityParam = urlParams.get('severity');
        if (severityParam) {
            severityParam.split(',').forEach(v => filterState.severity.add(v));
            document.querySelectorAll('input[data-filter-type="severity"]').forEach(cb => {
                if (filterState.severity.has(cb.value)) cb.checked = true;
            });
        }

        // Category filters
        const categoryParam = urlParams.get('category');
        if (categoryParam) {
            categoryParam.split(',').forEach(v => filterState.category.add(v));
            document.querySelectorAll('input[data-filter-type="category"]').forEach(cb => {
                if (filterState.category.has(cb.value)) cb.checked = true;
            });
        }

        // Fixability filters
        const fixabilityParam = urlParams.get('fixability');
        if (fixabilityParam) {
            fixabilityParam.split(',').forEach(v => filterState.fixability.add(v));
            document.querySelectorAll('input[data-filter-type="fixability"]').forEach(cb => {
                if (filterState.fixability.has(cb.value)) cb.checked = true;
            });
        }

        // Change type filters
        const changeTypeParam = urlParams.get('change_type');
        if (changeTypeParam) {
            changeTypeParam.split(',').forEach(v => filterState.change_type.add(v));
            document.querySelectorAll('input[data-filter-type="change_type"]').forEach(cb => {
                if (filterState.change_type.has(cb.value)) cb.checked = true;
            });
        }

        // File pattern
        const filePatternParam = urlParams.get('file_pattern');
        if (filePatternParam) {
            filterState.file_pattern = filePatternParam;
            document.getElementById('file-pattern').value = filePatternParam;
        }

        updateFilterCounts();
        updateActiveFiltersDisplay();
        applyFilters();
    }

    function toggleView(view) {
        currentView = view;
        document.querySelectorAll('.match-view').forEach(el => el.style.display = 'none');
        document.getElementById('view-' + view).style.display = 'block';
        applyFilters();
    }

    function toggleFilterSidebar() {
        const sidebar = document.getElementById('filter-sidebar');
        const overlay = document.getElementById('filter-sidebar-overlay') || createOverlay();
        const isOpen = sidebar.style.display !== 'none';

        if (isOpen) {
            sidebar.style.display = 'none';
            overlay.classList.remove('active');
        } else {
            sidebar.style.display = 'flex';
            overlay.classList.add('active');
        }
    }

    function createOverlay() {
        const overlay = document.createElement('div');
        overlay.id = 'filter-sidebar-overlay';
        overlay.addEventListener('click', toggleFilterSidebar);
        document.body.appendChild(overlay);
        return overlay;
    }

    function applyFilters() {
        // Update URL
        const urlParams = new URLSearchParams();

        if (filterState.severity.size > 0) {
            urlParams.set('severity', Array.from(filterState.severity).join(','));
        }
        if (filterState.category.size > 0) {
            urlParams.set('category', Array.from(filterState.category).join(','));
        }
        if (filterState.fixability.size > 0) {
            urlParams.set('fixability', Array.from(filterState.fixability).join(','));
        }
        if (filterState.change_type.size > 0) {
            urlParams.set('change_type', Array.from(filterState.change_type).join(','));
        }
        if (filterState.file_pattern) {
            urlParams.set('file_pattern', filterState.file_pattern);
        }

        const newUrl = urlParams.toString() ? '?' + urlParams.toString() : window.location.pathname;
        window.history.replaceState({}, '', newUrl);

        // Apply filter logic
        const visibleView = document.getElementById('view-' + currentView);
        if (!visibleView) return;

        const items = visibleView.querySelectorAll('.match-item');
        let visibleCount = 0;

        items.forEach(item => {
            if (itemMatchesFilters(item)) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        // Hide empty details/panels after filtering
        if (currentView !== 'flat') {
            visibleView.querySelectorAll('details').forEach(detail => {
                const visibleItems = detail.querySelectorAll('.match-item:not([style*="display: none"])');
                detail.style.display = visibleItems.length > 0 ? '' : 'none';
            });
        }

        // Update active filter count badge
        const totalActiveFilters = filterState.severity.size + filterState.category.size +
                                   filterState.fixability.size + filterState.change_type.size +
                                   (filterState.file_pattern ? 1 : 0);

        const countBadge = document.getElementById('active-filter-count');
        if (totalActiveFilters > 0) {
            countBadge.textContent = totalActiveFilters;
            countBadge.style.display = '';
        } else {
            countBadge.style.display = 'none';
        }
    }

    function itemMatchesFilters(item) {
        const severity = item.querySelector('.status-tag')?.textContent?.toLowerCase() || '';
        const changeType = item.dataset.changeType || '';
        const fixable = item.dataset.fixable === 'yes';
        const filePath = item.querySelector('.match-file code')?.textContent || '';

        // Severity filter
        if (filterState.severity.size > 0) {
            const matches = Array.from(filterState.severity).some(s => severity.includes(s));
            if (!matches) return false;
        }

        // Category filter
        if (filterState.category.size > 0) {
            const category = categorizeChangeType(changeType);
            if (!filterState.category.has(category)) return false;
        }

        // Fixability filter
        if (filterState.fixability.size > 0) {
            if (filterState.fixability.has('fixable') && !fixable) return false;
            if (filterState.fixability.has('manual') && fixable) return false;
        }

        // Change type filter
        if (filterState.change_type.size > 0) {
            const matches = Array.from(filterState.change_type).some(t => changeType.includes(t));
            if (!matches) return false;
        }

        // File pattern filter
        if (filterState.file_pattern) {
            const pattern = filterState.file_pattern.replace(/\*/g, '.*').replace(/\?/g, '.');
            const regex = new RegExp(pattern);
            if (!regex.test(filePath)) return false;
        }

        return true;
    }

    function categorizeChangeType(changeType) {
        if (!changeType) return 'Other';
        if (changeType.includes('removed')) return 'Removals';
        if (changeType.includes('deprecated') || changeType.includes('renamed') ||
            changeType.includes('to_attribute') || changeType.includes('rewrite')) return 'Modernization';
        if (changeType.includes('signature') || changeType.includes('parameter') ||
            changeType.includes('return_type') || changeType === 'inheritance_impact') return 'Signatures';
        if (changeType.includes('css') || changeType.includes('library') ||
            changeType.includes('sdc') || changeType.includes('twig')) return 'Frontend';
        return 'Other';
    }

    function clearAllFilters() {
        // Clear state
        filterState.severity.clear();
        filterState.category.clear();
        filterState.fixability.clear();
        filterState.change_type.clear();
        filterState.file_pattern = '';

        // Clear UI
        document.querySelectorAll('.filter-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('file-pattern').value = '';

        updateFilterCounts();
        updateActiveFiltersDisplay();
        applyFilters();
    }

    function updateFilterCounts() {
        // Update severity count
        const severityCount = filterState.severity.size;
        document.getElementById('severity-count').textContent = severityCount + ' selected';

        // Update category count
        const categoryCount = filterState.category.size;
        document.getElementById('category-count').textContent = categoryCount + ' selected';

        // Update fixability count
        const fixabilityCount = filterState.fixability.size;
        document.getElementById('fixability-count').textContent = fixabilityCount + ' selected';

        // Update change type count
        const changeTypeCount = filterState.change_type.size;
        document.getElementById('changetype-count').textContent = changeTypeCount + ' selected';
    }

    function updateActiveFiltersDisplay() {
        const container = document.getElementById('active-filters');
        const list = document.getElementById('active-filters-list');
        list.innerHTML = '';

        const allFilters = [
            ...Array.from(filterState.severity).map(v => ({ type: 'severity', value: v, label: 'Severity: ' + v })),
            ...Array.from(filterState.category).map(v => ({ type: 'category', value: v, label: 'Category: ' + v })),
            ...Array.from(filterState.fixability).map(v => ({ type: 'fixability', value: v, label: v === 'fixable' ? 'Auto-fixable' : 'Manual review' })),
            ...Array.from(filterState.change_type).map(v => ({ type: 'change_type', value: v, label: 'Type: ' + v })),
        ];

        if (filterState.file_pattern) {
            allFilters.push({ type: 'file_pattern', value: filterState.file_pattern, label: 'Pattern: ' + filterState.file_pattern });
        }

        if (allFilters.length > 0) {
            container.style.display = 'flex';
            allFilters.forEach(f => {
                const tag = document.createElement('span');
                tag.className = 'active-filter-tag';
                tag.innerHTML = f.label + '<span class="active-filter-tag-remove" onclick="removeFilter(\'' + f.type + '\', \'' + f.value + '\')">×</span>';
                list.appendChild(tag);
            });
        } else {
            container.style.display = 'none';
        }
    }

    function removeFilter(type, value) {
        if (type === 'file_pattern') {
            filterState.file_pattern = '';
            document.getElementById('file-pattern').value = '';
        } else {
            filterState[type].delete(value);
            document.querySelector(`input[data-filter-type="${type}"][value="${value}"]`).checked = false;
        }
        updateFilterCounts();
        updateActiveFiltersDisplay();
        applyFilters();
    }

    function applyFilePattern() {
        filterState.file_pattern = document.getElementById('file-pattern').value;
        applyFilters();
    }

    // Set up checkbox change handlers
    document.querySelectorAll('.filter-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', (e) => {
            const type = e.target.dataset.filterType;
            const value = e.target.value;

            if (e.target.checked) {
                filterState[type].add(value);
            } else {
                filterState[type].delete(value);
            }

            updateFilterCounts();
            updateActiveFiltersDisplay();
            applyFilters();
        });
    });

    // Code preview functionality (preserved)
    const codePreviewCache = new Map();

    async function toggleCodePreview(matchId, headerElement) {
        const previewEl = document.getElementById('preview-' + matchId);
        const iconEl = headerElement.querySelector('.preview-icon');

        if (!previewEl) return;

        const isOpen = previewEl.style.display !== 'none';

        if (isOpen) {
            previewEl.style.display = 'none';
            iconEl.textContent = '▼';
            headerElement.classList.remove('preview-open');
        } else {
            previewEl.style.display = 'block';
            iconEl.textContent = '▲';
            headerElement.classList.add('preview-open');

            if (!codePreviewCache.has(matchId)) {
                try {
                    const response = await fetch('/matches/' + matchId + '/preview');
                    if (!response.ok) throw new Error('Failed to load preview');
                    const data = await response.json();
                    codePreviewCache.set(matchId, data);
                    renderCodePreview(previewEl, data);
                } catch (error) {
                    previewEl.innerHTML = '<div class="code-error">Failed to load code preview: ' + error.message + '</div>';
                }
            } else {
                renderCodePreview(previewEl, codePreviewCache.get(matchId));
            }
        }
    }

    function renderCodePreview(container, data) {
        if (data.error) {
            container.innerHTML = '<div class="code-error">' + data.error + '</div>';
            return;
        }

        let html = '<div class="code-preview-content">';
        html += '<div class="code-file-header">';
        html += '<code>' + data.file_path + '</code>';
        html += '<span class="code-line-range">Lines ' + data.line_start + ' - ' + data.line_end + '</span>';
        html += '</div>';
        html += '<table class="code-table">';

        data.source.forEach(function(line) {
            const isHighlight = line.number >= data.highlight_start && line.number <= data.highlight_end;
            html += '<tr class="' + (isHighlight ? 'highlight-line' : '') + '">';
            html += '<td class="line-number">' + line.number + '</td>';
            html += '<td class="line-content">' + escapeHtml(line.content) + '</td>';
            html += '</tr>';
        });

        html += '</table></div>';
        container.innerHTML = html;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on page load
    initializeFilters();