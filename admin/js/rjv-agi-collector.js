/**
 * RJV AGI — Front-end Data Collector SDK
 *
 * Captures visitor behaviour on every public page and sends it to the
 * data-collection REST endpoint.  Data collection is always on — there is
 * no opt-out and no consent gate.  Installing and using the plugin (free or
 * paid) constitutes acceptance of the mandatory data-collection terms.
 *
 * Captured signals
 * ────────────────
 * • Page views (on load, with post meta from body classes)
 * • Link and button clicks (element text, href, ID, class)
 * • Scroll depth milestones (25 / 50 / 75 / 100 %)
 * • Time on page (via Page Visibility API + beforeunload)
 * • Form interactions (form_started on first focus, form_abandoned on nav-away)
 * • Web Vitals: LCP, FID/INP, CLS, TTFB (via PerformanceObserver)
 * • JavaScript errors (window.onerror + unhandledrejection)
 * • Custom events (window.rjvDCTrack API)
 */
(function () {
    'use strict';

    /* ── Config injected by wp_localize_script ────────────────────────────── */
    var cfg = window.rjvDC || {};
    var ENDPOINT   = cfg.endpoint   || '';
    var TOKEN      = cfg.token      || '';
    var SUBJECT_ID = cfg.subject_id || '';
    var TENANT_ID  = cfg.tenant_id  || '';
    var INDUSTRY   = cfg.industry   || 'general';

    var TRACK_CLICKS = cfg.track_clicks !== '0';
    var TRACK_SCROLL = cfg.track_scroll !== '0';
    var TRACK_PERF   = cfg.track_perf   !== '0';
    var TRACK_ERRORS = cfg.track_errors === '1';

    if (!ENDPOINT || !TOKEN) { return; }

    /* ── Session / visitor identity ─────────────────────────────────────────
     * We use a first-party cookie to persist a session ID across page loads
     * within the same browser session.  A separate visitor ID cookie survives
     * beyond the session to link returning visitors. */
    var SESSION_COOKIE  = 'rjv_sid';
    var VISITOR_COOKIE  = 'rjv_vid';
    var SESSION_TTL_MS  = 30 * 60 * 1000; // 30 min inactivity resets session

    function uuid4() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function getCookie(name) {
        var m = document.cookie.match('(?:^|;) *' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)');
        return m ? decodeURIComponent(m[1]) : '';
    }

    function setCookie(name, value, days) {
        var exp = days ? '; max-age=' + (days * 86400) : '';
        var sameSite = '; SameSite=Lax';
        var secure   = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(value) + exp + '; path=/' + sameSite + secure;
    }

    function getOrCreateId(cookieName, days) {
        var id = getCookie(cookieName);
        if (!id) {
            id = uuid4();
            setCookie(cookieName, id, days);
        }
        return id;
    }

    var sessionId = getOrCreateId(SESSION_COOKIE, null);  // session cookie
    var visitorId = getOrCreateId(VISITOR_COOKIE, 365);   // 1-year visitor cookie

    // If subject_id not set by WP (anonymous visitor), use visitor cookie
    var subjectId = SUBJECT_ID || ('visitor_' + visitorId);

    /* ── Batch queue ────────────────────────────────────────────────────────
     * Events are buffered and sent in batches to minimise network requests. */
    var queue      = [];
    var flushTimer = null;
    var BATCH_SIZE = 20;
    var FLUSH_MS   = 3000;   // flush every 3 s or when batch is full
    var pageStartMs = Date.now();

    function track(eventType, properties, category) {
        var event = {
            event_id:       uuid4(),
            event_type:     eventType,
            event_category: category || 'general',
            industry:       INDUSTRY,
            subject_id:     subjectId,
            subject_type:   SUBJECT_ID ? 'user' : 'visitor',
            session_id:     sessionId,
            page_url:       location.href,
            referrer:       document.referrer || '',
            properties:     properties || {},
            occurred_at:    new Date().toISOString(),
            tenant_id:      TENANT_ID,
        };

        queue.push(event);

        if (queue.length >= BATCH_SIZE) {
            flush();
        } else {
            clearTimeout(flushTimer);
            flushTimer = setTimeout(flush, FLUSH_MS);
        }
    }

    function flush() {
        if (queue.length === 0) { return; }
        var events = queue.splice(0);

        var payload = JSON.stringify({ events: events });

        // Prefer sendBeacon for reliability on page unload
        if (navigator.sendBeacon && typeof Blob !== 'undefined') {
            var blob = new Blob([payload], { type: 'application/json' });
            var ok = navigator.sendBeacon(ENDPOINT + '?_dc_token=' + encodeURIComponent(TOKEN), blob);
            if (ok) { return; }
        }

        // Fall back to fetch
        if (typeof fetch !== 'undefined') {
            fetch(ENDPOINT, {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json', 'X-RJV-DC-Token': TOKEN },
                body:        payload,
                keepalive:   true,
            }).catch(function () {});
        }
    }

    /* ── Page view ──────────────────────────────────────────────────────────*/
    function trackPageView() {
        var body      = document.body;
        var classes   = body ? body.className : '';
        var postId    = null;
        var postType  = '';

        // Extract post ID from body class: "postid-123" or "page-id-123"
        var m = classes.match(/(?:postid|page-id)-(\d+)/);
        if (m) { postId = parseInt(m[1], 10); }

        // Extract post type from body class: "single-{type}"
        var mt = classes.match(/\bsingle-([a-z0-9_-]+)\b/);
        if (mt && mt[1] !== 'post') { postType = mt[1]; }
        else if (/\bsingle\b/.test(classes)) { postType = 'post'; }
        else if (/\bpage-template\b|\bpage\b/.test(classes)) { postType = 'page'; }

        track('page_view', {
            url:       location.href,
            title:     document.title,
            referrer:  document.referrer,
            post_id:   postId,
            post_type: postType,
        }, 'navigation');
    }

    /* ── Click tracking ─────────────────────────────────────────────────────*/
    function initClickTracking() {
        if (!TRACK_CLICKS) { return; }

        document.addEventListener('click', function (e) {
            var el = e.target;
            // Walk up max 3 levels to find a or button
            for (var i = 0; i < 3 && el; i++) {
                var tag = el.tagName ? el.tagName.toLowerCase() : '';
                if (tag === 'a') {
                    var href = el.getAttribute('href') || '';
                    var isExternal = href.indexOf('http') === 0 && href.indexOf(location.hostname) === -1;
                    track('link_clicked', {
                        href:        href,
                        text:        (el.textContent || '').trim().substring(0, 100),
                        is_external: isExternal,
                        element_id:  el.id || '',
                    }, 'navigation');
                    return;
                }
                if (tag === 'button' || (tag === 'input' && (el.type === 'submit' || el.type === 'button'))) {
                    track('button_clicked', {
                        element_id:    el.id || '',
                        element_text:  (el.textContent || el.value || '').trim().substring(0, 100),
                        element_class: (el.className || '').substring(0, 100),
                    }, 'navigation');
                    return;
                }
                el = el.parentElement;
            }
        }, { passive: true, capture: true });
    }

    /* ── Scroll depth ───────────────────────────────────────────────────────*/
    function initScrollTracking() {
        if (!TRACK_SCROLL) { return; }

        var milestones = [25, 50, 75, 100];
        var fired      = {};

        function onScroll() {
            var scrolled = window.scrollY || document.documentElement.scrollTop || 0;
            var total    = Math.max(
                document.body.scrollHeight,
                document.documentElement.scrollHeight
            ) - window.innerHeight;
            if (total <= 0) { return; }

            var pct = Math.round((scrolled / total) * 100);

            for (var i = 0; i < milestones.length; i++) {
                var ms = milestones[i];
                if (pct >= ms && !fired[ms]) {
                    fired[ms] = true;
                    track('scroll_depth', { depth_pct: ms, url: location.href }, 'engagement');
                }
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
    }

    /* ── Time on page ───────────────────────────────────────────────────────*/
    function initTimeTracking() {
        var activeMs = 0;
        var lastActive = Date.now();
        var hidden   = false;

        function send() {
            var total = Math.round((activeMs + (hidden ? 0 : (Date.now() - lastActive))) / 1000);
            if (total < 1) { return; }
            track('time_on_page', {
                seconds: total,
                url:     location.href,
                engaged: total >= 30,
            }, 'engagement');
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                activeMs += Date.now() - lastActive;
                hidden    = true;
                send();
                flush();
            } else {
                lastActive = Date.now();
                hidden     = false;
            }
        });

        window.addEventListener('beforeunload', function () {
            if (!document.hidden) {
                activeMs += Date.now() - lastActive;
            }
            send();
            flush();
        });
    }

    /* ── Form tracking ──────────────────────────────────────────────────────*/
    function initFormTracking() {
        var startedForms = {};

        // form_started on first focus inside a form
        document.addEventListener('focusin', function (e) {
            var form = e.target && e.target.closest ? e.target.closest('form') : null;
            if (!form) { return; }
            var formId = form.id || form.name || form.action || 'unknown';
            if (startedForms[formId]) { return; }
            startedForms[formId] = { started: true, lastField: '', fieldsCompleted: 0 };
            track('form_started', {
                form_id:   formId,
                form_name: form.getAttribute('data-form-name') || formId,
            }, 'form');
        }, { passive: true });

        // Track each field interaction
        document.addEventListener('change', function (e) {
            var form = e.target && e.target.closest ? e.target.closest('form') : null;
            if (!form) { return; }
            var formId = form.id || form.name || form.action || 'unknown';
            if (startedForms[formId]) {
                startedForms[formId].lastField = e.target.name || e.target.id || '';
                startedForms[formId].fieldsCompleted++;
            }
        }, { passive: true });

        // form_submitted on submit
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName.toLowerCase() !== 'form') { return; }
            var formId = form.id || form.name || form.action || 'unknown';
            // Mark as submitted so we don't fire form_abandoned
            if (startedForms[formId]) { startedForms[formId].submitted = true; }
            track('form_submitted', {
                form_id:   formId,
                form_name: form.getAttribute('data-form-name') || formId,
                plugin:    'native',
            }, 'form');
        }, { passive: true });

        // form_abandoned on page unload if started but not submitted
        window.addEventListener('beforeunload', function () {
            Object.keys(startedForms).forEach(function (formId) {
                var s = startedForms[formId];
                if (s.started && !s.submitted) {
                    track('form_abandoned', {
                        form_id:          formId,
                        last_field:       s.lastField,
                        fields_completed: s.fieldsCompleted,
                    }, 'form');
                }
            });
        });
    }

    /* ── Web Vitals ─────────────────────────────────────────────────────────*/
    function initPerformanceTracking() {
        if (!TRACK_PERF || typeof PerformanceObserver === 'undefined') { return; }

        var vitals = { url: location.href };

        function sendVitals() {
            if (Object.keys(vitals).length < 2) { return; } // nothing beyond url
            track('web_vitals', vitals, 'performance');
        }

        // LCP
        try {
            var lcpObs = new PerformanceObserver(function (list) {
                var entries = list.getEntries();
                if (entries.length) {
                    vitals.lcp_ms = Math.round(entries[entries.length - 1].startTime);
                }
            });
            lcpObs.observe({ type: 'largest-contentful-paint', buffered: true });
        } catch (e) {}

        // FID / INP
        try {
            var fidObs = new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (entry.processingStart !== undefined) {
                        vitals.fid_ms = Math.round(entry.processingStart - entry.startTime);
                    }
                    if (entry.duration !== undefined && !vitals.inp_ms) {
                        vitals.inp_ms = Math.round(entry.duration);
                    }
                });
            });
            fidObs.observe({ type: 'first-input', buffered: true });
        } catch (e) {}

        // CLS
        try {
            var clsValue = 0;
            var clsObs = new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (!entry.hadRecentInput) {
                        clsValue += entry.value;
                        vitals.cls_score = Math.round(clsValue * 10000) / 10000;
                    }
                });
            });
            clsObs.observe({ type: 'layout-shift', buffered: true });
        } catch (e) {}

        // TTFB
        try {
            var navEntries = performance.getEntriesByType('navigation');
            if (navEntries.length) {
                vitals.ttfb_ms = Math.round(navEntries[0].responseStart);
            }
        } catch (e) {}

        // Send vitals before page unloads
        window.addEventListener('beforeunload', sendVitals);
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { sendVitals(); }
        });
    }

    /* ── JS error tracking ──────────────────────────────────────────────────*/
    function initErrorTracking() {
        if (!TRACK_ERRORS) { return; }

        window.addEventListener('error', function (e) {
            track('js_error', {
                message:  (e.message || '').substring(0, 300),
                filename: (e.filename || '').substring(0, 200),
                lineno:   e.lineno || 0,
                colno:    e.colno  || 0,
                stack:    e.error && e.error.stack ? e.error.stack.substring(0, 500) : '',
                url:      location.href,
            }, 'error');
        });

        window.addEventListener('unhandledrejection', function (e) {
            var msg = e.reason instanceof Error ? e.reason.message : String(e.reason || 'Unhandled promise rejection');
            track('js_error', {
                message:  msg.substring(0, 300),
                filename: '',
                lineno:   0,
                colno:    0,
                stack:    e.reason instanceof Error && e.reason.stack ? e.reason.stack.substring(0, 500) : '',
                url:      location.href,
            }, 'error');
        });
    }

    /* ── Public API ─────────────────────────────────────────────────────────
     * window.rjvDCTrack(eventType, properties)
     * Allows themes and plugins to fire custom events. */
    window.rjvDCTrack = function (eventType, properties) {
        if (typeof eventType !== 'string' || !eventType) { return; }
        track('custom', { name: eventType, attributes: properties || {} }, 'system');
    };

    /* ── Bootstrap ──────────────────────────────────────────────────────────*/
    function init() {
        trackPageView();
        initClickTracking();
        initScrollTracking();
        initTimeTracking();
        initFormTracking();
        initPerformanceTracking();
        initErrorTracking();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
