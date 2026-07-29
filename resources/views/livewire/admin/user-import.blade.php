<div @if ($running) wire:poll.2s @endif>
    <div class="mx-auto max-w-6xl px-4 py-6">
        @include('partials.admin-nav')

        <x-panel-heading title="Import employees"
                         description="Upload a CSV to create many accounts at once. Every row is checked on its own, so one bad line never stops the rest of the file." />

        {{-- Every state change on this screen is announced, including the polled ones. --}}
        <div role="status" aria-live="polite" class="mb-4">
            @if ($running)
                <div class="flex items-start gap-2 rounded-lg border border-line bg-info-tint px-3 py-2 text-sm text-info">
                    <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Import in progress. This page refreshes itself — you can leave it open.</span>
                </div>
            @elseif ($statusMessage)
                <div class="flex items-start gap-2 rounded-lg border border-brand-border bg-brand-tint px-3 py-2 text-sm text-brand-text">
                    <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ $statusMessage }}</span>
                </div>
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- ---------------------------------------------------------- upload --}}
            <div class="panel p-4">
                <h2 class="text-sm font-semibold text-content">Upload a file</h2>
                <p class="mt-1 text-xs text-content-muted">
                    Comma-separated <code class="font-mono">.csv</code> or <code class="font-mono">.txt</code>,
                    up to 2&nbsp;MB and {{ number_format($maxRows) }} rows.
                </p>

                {{-- Start from the template rather than guessing the columns. It
                     is generated on download, so the company and administration
                     names in it are always ones this hub will accept. --}}
                <div class="mt-3 rounded-lg border border-brand-border bg-brand-tint p-3">
                    <p class="text-xs font-semibold text-brand-text">New to this? Start with the template.</p>
                    <p class="mt-0.5 text-xs text-content-muted">
                        It has the correct header row and three filled-in example people using your real
                        departments. Delete the examples, add your staff, upload.
                    </p>

                    <div class="mt-2.5 flex flex-wrap gap-2">
                        <a href="{{ route('admin.import.template') }}" class="btn-primary !py-1.5 !text-xs">
                            <x-icon name="download" class="h-4 w-4" />
                            Download template
                        </a>
                        <a href="{{ route('admin.import.template', ['blank' => 1]) }}"
                           class="btn-secondary !py-1.5 !text-xs">
                            <x-icon name="download" class="h-4 w-4" />
                            Headers only
                        </a>
                    </div>
                </div>

                <form wire:submit="import" class="mt-4 space-y-4">
                    <div>
                        <x-input-label for="import-file" value="Employee file" />
                        <input id="import-file" type="file" class="field" accept=".csv,.txt"
                               wire:model="file"
                               aria-describedby="import-file-help" />
                        <p id="import-file-help" class="mt-1 text-xs text-content-subtle">
                            The first line must be the header row shown on the right.
                        </p>
                        <x-input-error :messages="$errors->get('file')" />

                        <p class="mt-2 text-xs text-content-muted" wire:loading wire:target="file">
                            Uploading&hellip;
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <x-primary-button wire:loading.attr="disabled" wire:target="file,import">
                            <x-icon name="upload" class="h-4 w-4" />
                            Start import
                        </x-primary-button>
                    </div>
                </form>

                <p class="mt-4 border-t border-line pt-3 text-xs text-content-subtle">
                    Imported people are created exactly like accounts added by hand: active, with a generated
                    temporary password they must change at first sign-in. Existing email addresses are skipped,
                    never overwritten.
                </p>
            </div>

            {{-- ---------------------------------------------------------- format --}}
            <div class="panel p-4">
                <h2 class="text-sm font-semibold text-content">Expected format</h2>

                <p class="mt-1 text-xs text-content-muted">
                    Header row, exactly these columns, in any order:
                </p>

                <ul class="mt-2 flex flex-wrap gap-1">
                    @foreach ($headers as $header)
                        <li class="badge-neutral font-mono lowercase">{{ $header }}</li>
                    @endforeach
                </ul>

                <dl class="mt-3 space-y-1 text-xs text-content-muted">
                    <div>
                        <dt class="inline font-semibold text-content">company / administration —</dt>
                        <dd class="inline"> matched by name, case-insensitive. Both must already exist; nothing is created for you.</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-content">role —</dt>
                        <dd class="inline"> <code class="font-mono">admin</code> or <code class="font-mono">employee</code>. Blank means employee.</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-content">phone / job_title —</dt>
                        <dd class="inline"> optional, may be left blank.</dd>
                    </div>
                </dl>

                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-content-muted">Sample</h3>
                <pre class="mt-1 overflow-x-auto rounded-md border border-line bg-surface-sunken p-3 font-mono text-2xs text-content"><code>{{ $sample }}</code></pre>

                {{-- Spelled out because the importer matches these by name and
                     rejects anything else. Guessing a department is the single
                     most common reason a row is skipped. --}}
                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-content-muted">
                    Names you can use
                </h3>

                @if ($organisation->isEmpty())
                    <p class="mt-1 text-xs text-content-muted">
                        No companies exist yet, so no import can succeed. Create a company and its
                        administrations first.
                    </p>
                @else
                    <dl class="mt-1 space-y-1.5">
                        @foreach ($organisation as $companyName => $administrationNames)
                            <div class="rounded-md border border-line bg-surface-sunken px-2.5 py-1.5">
                                <dt class="font-mono text-2xs font-semibold text-content">{{ $companyName }}</dt>
                                <dd class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($administrationNames as $administrationName)
                                        <span class="badge-neutral font-mono">{{ $administrationName }}</span>
                                    @endforeach
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-1.5 text-2xs text-content-subtle">
                        Case does not matter. An administration must belong to the company on the same row.
                    </p>
                @endif
            </div>
        </div>

        {{-- ---------------------------------------------------------- the report --}}
        @if ($report)
            <div class="panel mt-4 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-content">Last import report</h2>
                        <p class="mt-1 text-xs text-content-muted">Kept for one hour, then discarded.</p>
                    </div>

                    <button type="button" class="btn-secondary !py-1.5 text-xs" wire:click="clearReport">
                        <x-icon name="trash" class="h-4 w-4" />
                        Clear report
                    </button>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-brand-border bg-brand-tint px-3 py-2">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-text">Accounts created</p>
                        <p class="mt-0.5 text-xl font-semibold text-brand-text">{{ number_format($report->created()) }}</p>
                    </div>

                    <div class="rounded-lg border {{ $report->skipped() > 0 ? 'border-emergency-border bg-emergency-tint' : 'border-line bg-surface-sunken' }} px-3 py-2">
                        <p class="text-2xs font-semibold uppercase tracking-wide {{ $report->skipped() > 0 ? 'text-emergency-text' : 'text-content-muted' }}">
                            Rows rejected
                        </p>
                        <p class="mt-0.5 text-xl font-semibold {{ $report->skipped() > 0 ? 'text-emergency-text' : 'text-content' }}">
                            {{ number_format($report->skipped()) }}
                        </p>
                    </div>
                </div>

                @if ($report->wasTruncated())
                    <p class="mt-3 flex items-start gap-2 rounded-md border border-line bg-warning-tint px-3 py-2 text-xs text-warning">
                        <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            The file was longer than {{ number_format($maxRows) }} rows. Everything past that limit was
                            ignored — split the file and import the remainder.
                        </span>
                    </p>
                @endif

                @if ($report->isEmpty())
                    <p class="mt-4 rounded-md border border-line bg-surface-sunken px-3 py-6 text-center text-sm text-content-muted">
                        The file contained no data rows.
                    </p>
                @endif

                @if ($report->skipped() > 0)
                    <div class="mt-4 overflow-x-auto rounded-lg border border-line">
                        <table class="w-full border-collapse text-left text-sm">
                            <caption class="sr-only">Rows that were rejected, with the reason for each.</caption>

                            <thead class="bg-surface-sunken text-2xs uppercase tracking-wide text-content-muted">
                                <tr>
                                    <th scope="col" class="px-3 py-2 font-semibold">Row</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">Email</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">Why it was skipped</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($report->failures() as $failure)
                                    <tr class="border-t border-line align-top">
                                        <td class="px-3 py-2 font-mono text-xs text-content-muted">
                                            {{ $failure['row'] > 0 ? $failure['row'] : '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-content">{{ $failure['email'] ?? '—' }}</td>
                                        <td class="px-3 py-2">
                                            <ul class="space-y-0.5 text-xs text-emergency-text">
                                                @foreach ($failure['errors'] as $error)
                                                    <li class="flex items-start gap-1.5">
                                                        <x-icon name="alert" class="mt-0.5 h-3 w-3 shrink-0" />
                                                        <span>{{ $error }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif (! $report->isEmpty())
                    <p class="mt-4 flex items-center justify-center gap-2 rounded-md border border-line bg-surface-sunken px-3 py-6 text-sm text-content-muted">
                        <x-icon name="check-circle" class="h-4 w-4" />
                        Every row was imported. Nothing was rejected.
                    </p>
                @endif
            </div>
        @endif
    </div>
</div>
