<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Stevebauman\Location\Drivers\MaxMind;

/**
 * Where an IP is (country, region, city), from DB-IP's free "IP to City Lite" database read from
 * our own disk (docs/visitor-origin.md, workspace decision D29).
 *
 * DB-IP publishes the file in MaxMind's format, so the package's MaxMind driver reads it unchanged;
 * only the download differs: DB-IP serves one gzipped `.mmdb` a month with no account or key, where
 * MaxMind serves a tar archive behind a licence key (and GeoLite's free terms cover internal use
 * only). Licence: CC BY 4.0, so commercial use is allowed with a link to DB-IP on every page that
 * shows these places.
 */
class DbIpCityLite extends MaxMind
{
    public const DOWNLOAD_URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    /**
     * Download this month's file, or last month's while this month's is not out yet (it appears
     * early in the month), and swap it in. The package checks the file opens before replacing.
     */
    public function update(Command $command): void
    {
        $database = $this->getLocalDatabase();
        File::ensureDirectoryExists($database->directory());

        $gzip = $database->directory().'/dbip-city-lite.mmdb.gz';
        $mmdb = $database->directory().'/dbip-city-lite.download.mmdb';

        try {
            $month = $this->download($gzip, $command);
            $this->gunzip($gzip, $mmdb);
            $database->install($mmdb);
            $command->line("Installed DB-IP City Lite {$month}.");
        } finally {
            File::delete([$gzip, $mmdb]);
        }
    }

    /**
     * @return string the month downloaded, as YYYY-MM
     */
    private function download(string $target, Command $command): string
    {
        $now = CarbonImmutable::now('UTC');

        foreach ([$now, $now->subMonthNoOverflow()] as $month) {
            $stamp = $month->format('Y-m');
            $response = Http::timeout(600)->withOptions(['sink' => $target])->get(sprintf(self::DOWNLOAD_URL, $stamp));

            if ($response->successful()) {
                return $stamp;
            }

            $command->line("DB-IP City Lite {$stamp} is not available yet (HTTP {$response->status()}).");
        }

        throw new RuntimeException('Could not download DB-IP City Lite for this month or last month.');
    }

    private function gunzip(string $source, string $target): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($target, 'wb');

        throw_if($in === false || $out === false, new RuntimeException('Could not open the DB-IP download.'));

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1 << 20);
                throw_if($chunk === false, new RuntimeException('The DB-IP download is not a valid gzip file.'));
                fwrite($out, $chunk);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }
}
