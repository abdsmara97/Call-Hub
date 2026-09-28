<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoStaffSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes docs/test-accounts.xlsx: sign-in details for the seeded demo accounts,
 * plus a test script for the paths that need two people.
 *
 * Built from the database rather than from the seeder source, so the sheet
 * cannot claim an account that is not actually there. Re-run it after re-seeding
 * — a stale credentials sheet is worse than none, because people trust it.
 *
 * SEED CREDENTIALS ONLY. This deliberately contains no infrastructure secrets:
 * no database password, no Cloudflare TURN token, no VAPID private key, no
 * Reverb app secret. Those live in .env, which is gitignored so they cannot be
 * committed; the file this writes is not gitignored, and putting a real secret
 * in it would quietly undo that protection.
 */
class ExportTestAccounts extends Command
{
    protected $signature = 'hub:export-test-accounts {--path= : Where to write the workbook}';

    protected $description = 'Export the seeded demo accounts and a two-person test script to an Excel workbook';

    private const BRAND = 'FF0D3A88';

    private const HEADER_TEXT = 'FFFFFFFF';

    private const ZEBRA = 'FFEFF6FF';

    private const GRID = 'FFD9E3F5';

    private const WARN_FILL = 'FFFEF2F2';

    private const WARN_TEXT = 'FF991B1B';

    public function handle(): int
    {
        $users = User::with(['company', 'administration', 'roles'])->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->components->error('No accounts found. Run `php artisan migrate --seed` first.');

            return self::FAILURE;
        }

        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator('Saai')
            ->setTitle('Saai — seeded test accounts')
            ->setDescription('Sign-in details for the seeded demo accounts. Local and demo use only.');

        $this->buildAccounts($book->getActiveSheet(), $users);
        $this->buildScenarios($book->createSheet(), $users);
        $this->buildAbout($book->createSheet(), $users);

        $book->setActiveSheetIndex(0);

        $path = $this->option('path') ?: base_path('docs/test-accounts.xlsx');

        (new Xlsx($book))->save($path);

        $this->components->info(sprintf(
            'Wrote %s — %d accounts across %d sheets.',
            $path,
            $users->count(),
            $book->getSheetCount(),
        ));

        return self::SUCCESS;
    }

    /** @param  Collection<int, User>  $users */
    private function buildAccounts(Worksheet $sheet, $users): void
    {
        $sheet->setTitle('Accounts');

        $sheet->fromArray([
            'Name', 'Email', 'Password', 'Role', 'Company', 'Administration',
            'Job title', 'Status', 'Availability', 'Must change password',
        ], null, 'A1');

        $this->styleHeader($sheet, 'J');

        $row = 2;
        foreach ($users as $user) {
            $sheet->fromArray([
                $user->name,
                $user->email,
                DemoStaffSeeder::PASSWORD,
                $user->roles->pluck('name')->map(fn ($role) => ucfirst($role))->join(', ') ?: '—',
                $user->company->name ?? '—',
                $user->administration->name ?? '—',
                $user->job_title ?: '—',
                ucfirst($user->status->value),
                $user->availability->label(),
                $user->must_change_password ? 'Yes' : 'No',
            ], null, "A{$row}");

            // The suspended account exists so the admin console has something to
            // show. Worth seeing at a glance rather than discovering on row 19.
            if ($user->status->value !== 'active') {
                $sheet->getStyle("A{$row}:J{$row}")->getFont()->getColor()->setARGB(self::WARN_TEXT);
            }

            $row++;
        }

        $last = $row - 1;

        $this->zebra($sheet, 'J', 2, $last);
        $this->widths($sheet, [
            'A' => 24, 'B' => 34, 'C' => 14, 'D' => 12, 'E' => 26,
            'F' => 30, 'G' => 26, 'H' => 12, 'I' => 14, 'J' => 12,
        ]);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:J{$last}");
        $sheet->getStyle("B2:C{$last}")->getFont()->setName('Consolas');
    }

    /** @param  Collection<int, User>  $users */
    private function buildScenarios(Worksheet $sheet, $users): void
    {
        $sheet->setTitle('Test scenarios');

        $admin = $users->first(fn (User $u) => $u->roles->pluck('name')->contains('admin'));
        $staff = $users->filter(fn (User $u) => $u->status->value === 'active'
            && ! $u->roles->pluck('name')->contains('admin'))->values();
        $suspended = $users->first(fn (User $u) => $u->status->value !== 'active');

        $a = $staff->get(0)?->email ?? '—';
        $b = $staff->get(1)?->email ?? '—';
        $c = $staff->get(2)?->email ?? '—';

        $sheet->fromArray(['What to test', 'Sign in as', 'And as', 'What should happen'], null, 'A1');
        $this->styleHeader($sheet, 'D');

        $rows = [
            ['1:1 audio call', $a, $b, 'Open the direct message and press the phone button. The other browser shows an incoming call overlay and rings. Answer — both sides reach "Connected".'],
            ['Video call', $a, $b, 'Press the video button, or answer with video. The panel grows to show both streams.'],
            ['Decline a call', $a, $b, 'Decline on the receiving side. The caller is told "Call declined." and offered "Send a message".'],
            ['No answer', $a, $b, 'Do not answer. After the ring duration set in Admin → Settings the caller sees "No answer."'],
            ['Call someone offline', $a, 'nobody signed in', 'The caller is told "They are not online right now" within a few seconds — deliberately a different message from "no answer".'],
            ['Call someone already on a call', $a, $b.' + '.$c, 'Ring a person who is mid-call. The caller is told "They are on another call."'],
            ['Do Not Disturb rings quietly', $a, $b, 'Give the callee a DND window covering now, in Profile. The overlay still appears but makes no sound, and the caller is told it is ringing quietly.'],
            ['Off shift blocks the call', $a, $b, 'Set the callee to Off shift. The call is refused outright. DND silences a call; off shift prevents it.'],
            ['Calling can be switched off', $admin?->email ?? '—', $a, 'Admin → Settings → Calling → uncheck "Allow calling". The call buttons disappear for everyone, with no deploy.'],
            ['A call survives a re-render', $a, $b, 'While connected, send a message in another room. The call must not drop — the panel is wire:ignore for exactly this reason.'],
            ['Emergency overrides DND', $a, $b, 'Raise an emergency at a recipient inside a DND window. It alerts anyway — that is the point of the override.'],
            ['Emergency reaches another room', $a, $b, 'Put the recipient in a different room. The overlay still appears, because emergencies also broadcast on the personal channel.'],
            ['Suspended account loses access', $admin?->email ?? '—', $suspended?->email ?? '—', 'The suspended account cannot sign in. Suspending someone mid-session ends it on their next request, not at their next login.'],
            ['Password rotation screen', $admin?->email ?? '—', 'a newly created user', 'Create a user in Admin → Users. That account is held on the rotation screen until it sets its own password. Seeded accounts skip this.'],
        ];

        $row = 2;
        foreach ($rows as $entry) {
            $sheet->fromArray($entry, null, "A{$row}");
            $row++;
        }

        $last = $row - 1;

        $this->zebra($sheet, 'D', 2, $last);
        $this->widths($sheet, ['A' => 30, 'B' => 34, 'C' => 36, 'D' => 82]);

        $sheet->getStyle("A2:D{$last}")->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle("B2:C{$last}")->getFont()->setName('Consolas');
        $sheet->freezePane('A2');
    }

    /** @param  Collection<int, User>  $users */
    private function buildAbout(Worksheet $sheet, $users): void
    {
        $sheet->setTitle('About');

        $admin = $users->first(fn (User $u) => $u->roles->pluck('name')->contains('admin'));
        $suspended = $users->filter(fn (User $u) => $u->status->value !== 'active')->count();

        $sheet->setCellValue('A1', 'Saai — seeded test accounts');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB(self::BRAND);

        $lines = [
            ['', ''],
            ['Read this first', ''],
            ['', 'These are the accounts created by `php artisan migrate --seed`. They exist so the app can be'],
            ['', 'demonstrated and tested. They all share the same well-known password, which is already'],
            ['', 'published in the README, so this sheet exposes nothing that was not public already.'],
            ['', ''],
            ['', 'None of this belongs in production. Real accounts are created by an administrator and are held'],
            ['', 'on the password-rotation screen until the person sets their own password.'],
            ['', ''],
            ['What is NOT here', ''],
            ['', 'No infrastructure secrets. The database password, Cloudflare TURN API token, VAPID private key'],
            ['', 'and Reverb app secret are not in this file and must never be put in one. They live in .env,'],
            ['', 'which is gitignored so they cannot be committed — this workbook is not.'],
            ['', ''],
            ['Shared password', DemoStaffSeeder::PASSWORD],
            ['Administrator', $admin?->email ?? '—'],
            ['Total accounts', (string) $users->count()],
            ['Suspended', (string) $suspended],
            ['', ''],
            ['Running the app', ''],
            ['', 'php artisan serve            then open http://127.0.0.1:8000'],
            ['', 'php artisan reverb:start     required — without it nothing is live: no messages, no ringing'],
            ['', 'php artisan queue:work       emergency fan-out and escalation'],
            ['', 'php artisan schedule:work    the escalation sweep backstop'],
            ['', ''],
            ['Testing two people', ''],
            ['', 'One browser cannot hold two sessions. Use a normal window plus an incognito window, or two'],
            ['', 'different browsers. Calling and emergency delivery both need two real sessions to prove.'],
            ['', ''],
            ['Regenerating', ''],
            ['', 'php artisan hub:export-test-accounts'],
            ['', ''],
            ['', 'Built from the database, not from the seeder source, so it cannot claim an account that is'],
            ['', 'not there. Re-run it after re-seeding — a stale credentials sheet is worse than none.'],
        ];

        $row = 2;
        $warnFrom = null;
        $warnTo = null;

        foreach ($lines as [$label, $text]) {
            if ($label === 'What is NOT here') {
                $warnFrom = $row;
            }

            if ($label !== '') {
                $sheet->setCellValue("A{$row}", $label);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            }

            if ($text !== '') {
                $sheet->setCellValue("B{$row}", $text);
            }

            if ($warnFrom !== null && $warnTo === null && str_contains($text, 'this workbook is not')) {
                $warnTo = $row;
            }

            $row++;
        }

        if ($warnFrom !== null && $warnTo !== null) {
            $sheet->getStyle("A{$warnFrom}:B{$warnTo}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::WARN_FILL);
            $sheet->getStyle("A{$warnFrom}:B{$warnTo}")->getFont()->getColor()->setARGB(self::WARN_TEXT);
        }

        $this->widths($sheet, ['A' => 20, 'B' => 100]);

        // The command lines and the password read as literals, not prose.
        foreach (range(2, $row) as $line) {
            $value = (string) $sheet->getCell("B{$line}")->getValue();

            if (str_starts_with($value, 'php artisan') || $value === DemoStaffSeeder::PASSWORD) {
                $sheet->getStyle("B{$line}")->getFont()->setName('Consolas');
            }
        }
    }

    private function styleHeader(Worksheet $sheet, string $lastColumn): void
    {
        $range = "A1:{$lastColumn}1";

        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB(self::HEADER_TEXT);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::BRAND);
        $sheet->getStyle($range)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(26);
    }

    private function zebra(Worksheet $sheet, string $lastColumn, int $first, int $last): void
    {
        for ($row = $first; $row <= $last; $row++) {
            if (($row - $first) % 2 === 1) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB(self::ZEBRA);
            }
        }

        $sheet->getStyle("A{$first}:{$lastColumn}{$last}")
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB(self::GRID);
    }

    /** @param  array<string, int>  $map */
    private function widths(Worksheet $sheet, array $map): void
    {
        foreach ($map as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }
}
