<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Migration Engine — SportsEngine → TeamLinkt demo</title>
    <style>
        :root {
            --bg: #0d1117;
            --surface: #161b22;
            --surface-hi: #21262d;
            --border: #30363d;
            --text: #e6edf3;
            --muted: #7d8590;
            --primary: #2f81f7;
            --primary-hi: #4493f8;
            --success: #3fb950;
            --warn: #d29922;
            --error: #f85149;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background: var(--bg); color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Instrument Sans", sans-serif;
            min-height: 100vh;
        }
        .shell {
            max-width: 720px;
            margin: 0 auto;
            padding: 60px 24px 40px;
        }
        h1 {
            font-size: 32px;
            font-weight: 600;
            margin: 0 0 8px 0;
            letter-spacing: -0.02em;
        }
        .subtitle {
            color: var(--muted);
            font-size: 15px;
            margin: 0 0 40px 0;
            line-height: 1.5;
        }
        .subtitle code {
            background: var(--surface); padding: 2px 6px; border-radius: 4px;
            font-size: 13px;
            color: var(--text);
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 24px;
        }
        label {
            display: block;
            font-size: 13px; font-weight: 500; margin-bottom: 8px;
            color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        input[type="url"] {
            width: 100%;
            padding: 12px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 15px;
            color: var(--text);
            font-family: inherit;
        }
        input[type="url"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(47, 129, 247, 0.2);
        }
        .chips {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-top: 12px;
        }
        .chip {
            background: var(--surface-hi);
            border: 1px solid var(--border);
            color: var(--muted);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 13px;
            cursor: pointer;
            transition: color .15s, border-color .15s;
        }
        .chip:hover, .chip.is-active {
            color: var(--text);
            border-color: var(--primary);
        }
        button.convert {
            display: block;
            margin-top: 20px;
            padding: 12px 24px;
            background: var(--primary);
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
        }
        button.convert:hover { background: var(--primary-hi); }
        button.convert:disabled { background: var(--surface-hi); cursor: not-allowed; color: var(--muted); }
        .watching {
            display: none;
            padding-top: 8px;
        }
        .watching.is-visible { display: block; }
        .stage-line {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        .stage-detail {
            color: var(--muted);
            font-size: 14px;
        }
        .progress-bar {
            margin-top: 16px;
            height: 6px;
            background: var(--surface-hi);
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width .3s ease;
            width: 0;
        }
        .elapsed {
            font-size: 12px; color: var(--muted); margin-top: 12px;
            font-variant-numeric: tabular-nums;
        }
        .stage-list {
            list-style: none; padding: 0; margin: 16px 0 0 0;
            font-size: 13px;
        }
        .stage-list li {
            padding: 4px 0; color: var(--muted);
            display: flex; align-items: center; gap: 8px;
        }
        .stage-list li.done { color: var(--success); }
        .stage-list li.active { color: var(--text); font-weight: 500; }
        .stage-list .marker { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--border); }
        .stage-list li.done .marker { background: var(--success); }
        .stage-list li.active .marker { background: var(--primary); animation: pulse 1.5s ease infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
        .failure {
            display: none;
            padding: 16px;
            background: rgba(248, 81, 73, 0.08);
            border: 1px solid rgba(248, 81, 73, 0.4);
            border-radius: 6px;
            margin-top: 20px;
        }
        .failure.is-visible { display: block; }
        .failure-title { color: var(--error); font-weight: 500; margin-bottom: 6px; }
        .failure-reason { color: var(--muted); font-size: 13px; word-break: break-word; }
        .footer {
            margin-top: 32px;
            color: var(--muted);
            font-size: 12px;
        }
        .footer code { background: var(--surface); padding: 1px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="shell">
        <h1>Migration Engine</h1>
        <p class="subtitle">
            Paste a SportsEngine URL. The engine converts it to a TeamLinkt draft site in a few
            minutes: extract the structure, plan the pages, rebuild content with real copy, and
            land a preview. Live conversion — watch the stages tick through.
        </p>

        <div class="card">
            <div id="triggerView">
                <label for="urlInput">SportsEngine URL</label>
                <input
                    type="url"
                    id="urlInput"
                    value="{{ $lead_url }}"
                    placeholder="https://www.example-club.org/"
                    autocomplete="off"
                />

                @if (count($allowlist) > 1)
                    <div class="chips" id="chips">
                        @foreach ($allowlist as $url)
                            <button
                                type="button"
                                class="chip {{ $url === $lead_url ? 'is-active' : '' }}"
                                data-url="{{ $url }}"
                            >{{ parse_url($url, PHP_URL_HOST) }}</button>
                        @endforeach
                    </div>
                @endif

                <label for="orgTypeInput" style="margin-top:16px;">Org type</label>
                <select id="orgTypeInput" name="orgType">
                    <option value="club" selected>Club</option>
                    <option value="association">Association</option>
                    <option value="league">League</option>
                    <option value="high_school">High school</option>
                    <option value="civic">Civic organization</option>
                    <option value="multi_location">Multi-location organization</option>
                </select>

                <button class="convert" id="convertBtn" type="button">Convert →</button>
            </div>

            <div class="watching" id="watchingView">
                <div class="stage-line" id="stageLine">Starting…</div>
                <div class="stage-detail" id="stageDetail"></div>
                <div class="progress-bar" id="progressBar" style="display:none;">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <ul class="stage-list" id="stageList">
                    <li data-stage="ingest"><span class="marker"></span>Reading your site structure</li>
                    <li data-stage="plan"><span class="marker"></span>Planning the rebuild</li>
                    <li data-stage="ir_pass"><span class="marker"></span>Designing pages</li>
                    <li data-stage="block_fill"><span class="marker"></span>Rebuilding pages with real content</li>
                    <li data-stage="finalize"><span class="marker"></span>Assembling the draft</li>
                </ul>
                <div class="elapsed" id="elapsed"></div>
            </div>

            <div class="failure" id="failureView">
                <div class="failure-title">Conversion failed</div>
                <div class="failure-reason" id="failureReason"></div>
                <button class="convert" id="retryBtn" type="button" style="margin-top:14px;">Try again</button>
            </div>
        </div>

        <div class="footer">
            Cost-guarded demo. Powered by <code>engine:capture-live</code> under the hood.
        </div>
    </div>

    <script>
        const config = {
            demoToken: @json($demo_token),
            allowlist: @json($allowlist),
            leadUrl: @json($lead_url),
        };

        const triggerView = document.getElementById('triggerView');
        const watchingView = document.getElementById('watchingView');
        const failureView = document.getElementById('failureView');
        const urlInput = document.getElementById('urlInput');
        const convertBtn = document.getElementById('convertBtn');
        const chipsEl = document.getElementById('chips');
        const stageLine = document.getElementById('stageLine');
        const stageDetail = document.getElementById('stageDetail');
        const stageList = document.getElementById('stageList');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');
        const elapsedEl = document.getElementById('elapsed');
        const failureReason = document.getElementById('failureReason');
        const retryBtn = document.getElementById('retryBtn');

        const STAGE_ORDER = ['queued', 'ingest', 'plan', 'ir_pass', 'block_fill', 'finalize'];

        if (chipsEl) {
            chipsEl.addEventListener('click', (e) => {
                const btn = e.target.closest('.chip');
                if (!btn) return;
                urlInput.value = btn.dataset.url;
                chipsEl.querySelectorAll('.chip').forEach((c) => c.classList.remove('is-active'));
                btn.classList.add('is-active');
            });
        }

        convertBtn.addEventListener('click', () => trigger());
        retryBtn.addEventListener('click', () => resetToTrigger());

        function resetToTrigger() {
            failureView.classList.remove('is-visible');
            watchingView.classList.remove('is-visible');
            triggerView.style.display = '';
        }

        async function trigger() {
            const url = urlInput.value.trim();
            if (!url) return;
            const orgType = (document.getElementById('orgTypeInput')?.value) || 'club';

            convertBtn.disabled = true;
            failureView.classList.remove('is-visible');

            let response;
            try {
                response = await fetch('/api/conversions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Demo-Token': config.demoToken,
                    },
                    body: JSON.stringify({ url, orgType }),
                });
            } catch (err) {
                convertBtn.disabled = false;
                showFailure('Network error — is the demo server reachable? ' + err.message);
                return;
            }

            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                convertBtn.disabled = false;
                showFailure(body.error || `Error ${response.status}. Try again in a moment.`);
                return;
            }

            // Successful POST — transition to watching view. Both 202
            // (fresh) and 200 (deduped) enter watching; if deduped, the
            // status endpoint may already report terminal — the polling
            // handler treats that natively (immediate redirect).
            triggerView.style.display = 'none';
            watchingView.classList.add('is-visible');
            pollStatus(body.conversion_id, body.preview_url);
        }

        function showFailure(reason) {
            triggerView.style.display = '';
            watchingView.classList.remove('is-visible');
            failureView.classList.add('is-visible');
            failureReason.textContent = reason;
        }

        function updateStageList(currentStage) {
            const idx = STAGE_ORDER.indexOf(currentStage);
            stageList.querySelectorAll('li').forEach((li) => {
                const stage = li.dataset.stage;
                const stageIdx = STAGE_ORDER.indexOf(stage);
                li.classList.remove('done', 'active');
                if (stageIdx < idx) li.classList.add('done');
                if (stageIdx === idx) li.classList.add('active');
            });
        }

        async function pollStatus(conversionId, previewUrl) {
            let consecutiveErrors = 0;
            while (true) {
                let snapshot;
                try {
                    const res = await fetch(`/api/conversions/${conversionId}/status`, {
                        headers: { 'Accept': 'application/json', 'X-Demo-Token': config.demoToken },
                    });
                    if (!res.ok) throw new Error(`status ${res.status}`);
                    snapshot = await res.json();
                    consecutiveErrors = 0;
                } catch (err) {
                    consecutiveErrors++;
                    if (consecutiveErrors > 5) {
                        showFailure('Lost connection to the demo server after several retries.');
                        return;
                    }
                    await sleep(3000);
                    continue;
                }

                stageLine.textContent = snapshot.stage_label || snapshot.stage;
                elapsedEl.textContent = `${snapshot.elapsed_seconds || 0}s elapsed`;
                updateStageList(snapshot.stage);

                const bfp = snapshot.block_fill_progress;
                if (snapshot.stage === 'block_fill' && bfp) {
                    stageDetail.textContent = `${bfp.done} of ${bfp.total} pages rebuilt`;
                    progressBar.style.display = '';
                    const pct = bfp.total > 0 ? Math.min(100, Math.round((bfp.done / bfp.total) * 100)) : 0;
                    progressFill.style.width = pct + '%';
                } else {
                    stageDetail.textContent = '';
                    progressBar.style.display = 'none';
                }

                const final = snapshot.final_status;
                if (final === 'complete' || final === 'partial') {
                    // Redirect to preview.
                    window.location.href = previewUrl || `/preview/${conversionId}`;
                    return;
                }
                if (final === 'failed') {
                    showFailure(snapshot.failure_reason || 'Conversion failed with no specific reason.');
                    return;
                }

                await sleep(2000);
            }
        }

        function sleep(ms) {
            return new Promise((r) => setTimeout(r, ms));
        }
    </script>
</body>
</html>
