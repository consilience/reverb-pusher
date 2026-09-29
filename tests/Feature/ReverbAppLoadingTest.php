<?php

use App\Models\ReverbApp;
use App\Providers\ReverbServiceProvider;
use App\Support\ActiveReverbApps;
use App\Support\LoopbackApp;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/*
 * The provider reads the apps once, when the application has booted. Each
 * test's application boots before the test body runs, so before any fake is
 * bound and before RefreshDatabase has migrated the in-memory database; that
 * first load always fails quietly. These tests therefore set up the process
 * context and the database, then boot the provider again. Laravel runs a
 * booted() callback straight away on an application that has already booted,
 * so this follows the same path as a freshly started process.
 */
function bootReverbProviderAgain(): void
{
    app()->getProvider(ReverbServiceProvider::class)->boot();
}

/**
 * Make the application look as if it was started by the given command line.
 * The console flag is cached on the application the first time it is read,
 * so the web case has to overwrite the cached value.
 *
 * @param  list<string>|null  $argv
 */
function pretendProcessIs(?array $argv, bool $runningInConsole = true): void
{
    if ($argv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $argv;
    }

    (fn () => $this->isRunningInConsole = $runningInConsole)->call(app());
}

function pretendToBeTheReverbServer(): void
{
    pretendProcessIs(['artisan', 'reverb:start', '--host=0.0.0.0', '--port=8080']);
}

/**
 * Stand in for the database being unreachable: the first $failures reads
 * throw the kind of error a refused connection produces, and any later read
 * runs the real query. $secondsPerFailure moves the clock on for each failed
 * read, to mimic a connection attempt that takes a while to time out.
 */
function databaseFailsFor(int $failures, int $secondsPerFailure = 0): ActiveReverbApps
{
    $source = new class($failures, $secondsPerFailure) extends ActiveReverbApps
    {
        public int $attempts = 0;

        public function __construct(
            private int $failures,
            private int $secondsPerFailure,
        ) {}

        public function fetch(): Collection
        {
            $this->attempts++;

            if ($this->attempts <= $this->failures) {
                Carbon::setTestNow(Carbon::now()->addSeconds($this->secondsPerFailure));

                throw new PDOException("SQLSTATE[HY000] [2002] Connection refused (attempt {$this->attempts})");
            }

            return parent::fetch();
        }
    };

    app()->instance(ActiveReverbApps::class, $source);

    return $source;
}

/**
 * @param  list<int>  $seconds
 * @return list<Sleep>
 */
function sleepsOf(array $seconds): array
{
    return array_map(fn (int $second) => Sleep::for($second)->seconds(), $seconds);
}

beforeEach(function () {
    $this->originalArgv = $_SERVER['argv'] ?? null;

    // Creating an app dispatches a restart job; keep it off the sync queue.
    Queue::fake();
    Sleep::fake();
    $this->freezeTime();
    Log::spy();

    $this->totalSecondsSlept = 0;
    Sleep::whenFakingSleep(function (CarbonInterval $duration) {
        $this->totalSecondsSlept += $duration->totalSeconds;
    });
});

afterEach(function () {
    if ($this->originalArgv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $this->originalArgv;
    }
});

describe('inside the reverb:start process', function () {
    it('loads the active apps without waiting when the database is available', function () {
        ReverbApp::factory()->create([
            'app_id' => 'app-live',
            'app_key' => 'key-live',
            'app_secret' => 'secret-live',
            'allowed_origins' => ['example.com'],
        ]);
        ReverbApp::factory()->inactive()->create(['app_id' => 'app-disabled']);
        pretendToBeTheReverbServer();

        bootReverbProviderAgain();

        Sleep::assertNeverSlept();

        $apps = config('reverb.apps.apps');
        expect($apps)->toHaveCount(2)
            ->and($apps[0])->toMatchArray([
                'app_id' => 'app-live',
                'key' => 'key-live',
                'secret' => 'secret-live',
                'allowed_origins' => ['example.com'],
            ])
            ->and($apps[1]['app_id'])->toBe(LoopbackApp::appId());
    });

    it('keeps retrying while the database is unreachable and carries on once it answers', function () {
        ReverbApp::factory()->create(['app_id' => 'app-live']);
        $database = databaseFailsFor(2);
        pretendToBeTheReverbServer();

        bootReverbProviderAgain();

        Sleep::assertSequence(sleepsOf([1, 2]));
        expect($database->attempts)->toBe(3)
            ->and(array_column(config('reverb.apps.apps'), 'app_id'))
            ->toBe(['app-live', LoopbackApp::appId()]);
        Log::shouldNotHaveReceived('error');
    });

    it('gives up with an error once the retry window has passed if the database never answers', function () {
        ReverbApp::factory()->create();
        $database = databaseFailsFor(PHP_INT_MAX);
        pretendToBeTheReverbServer();

        expect(fn () => bootReverbProviderAgain())
            ->toThrow(RuntimeException::class, 'Connection refused (attempt 12)');

        // Doubling waits capped at 15s, fitted to the default 120s window.
        Sleep::assertSequence(sleepsOf([1, 2, 4, 8, 15, 15, 15, 15, 15, 15, 15]));
        expect($this->totalSecondsSlept)->toBeLessThanOrEqual(120)
            ->and($database->attempts)->toBe(12);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context = []) => ($context['reason'] ?? null)
                === 'SQLSTATE[HY000] [2002] Connection refused (attempt 12)');
    });

    it('treats a database with no active apps as a failure and gives up after the retry window', function () {
        ReverbApp::factory()->inactive()->create();
        pretendToBeTheReverbServer();

        expect(fn () => bootReverbProviderAgain())
            ->toThrow(RuntimeException::class, 'no active apps');

        Sleep::assertSequence(sleepsOf([1, 2, 4, 8, 15, 15, 15, 15, 15, 15, 15]));
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context = []) => ($context['reason'] ?? null) === 'no active apps');
    });

    it('picks up an app that is added while it is retrying', function () {
        pretendToBeTheReverbServer();
        Sleep::whenFakingSleep(function () {
            if (ReverbApp::count() === 0) {
                ReverbApp::factory()->create(['app_id' => 'app-added-later']);
            }
        });

        bootReverbProviderAgain();

        Sleep::assertSequence(sleepsOf([1]));
        expect(array_column(config('reverb.apps.apps'), 'app_id'))
            ->toBe(['app-added-later', LoopbackApp::appId()]);
    });

    it('uses the configured retry window and cap on each wait', function () {
        config([
            'reverb.startup.retry_window' => 10,
            'reverb.startup.max_retry_delay' => 3,
        ]);
        $database = databaseFailsFor(PHP_INT_MAX);
        pretendToBeTheReverbServer();

        expect(fn () => bootReverbProviderAgain())->toThrow(RuntimeException::class);

        Sleep::assertSequence(sleepsOf([1, 2, 3, 3, 1]));
        expect($database->attempts)->toBe(6);
    });

    it('counts slow connection attempts towards the retry window', function () {
        Sleep::syncWithCarbon();
        $database = databaseFailsFor(PHP_INT_MAX, secondsPerFailure: 30);
        pretendToBeTheReverbServer();

        expect(fn () => bootReverbProviderAgain())->toThrow(RuntimeException::class);

        // Attempts end at 30s, 61s, 93s and 127s; the fourth is past 120s.
        Sleep::assertSequence(sleepsOf([1, 2, 4]));
        expect($database->attempts)->toBe(4);
    });
});

describe('outside the reverb:start process', function () {
    dataset('other processes', [
        'web request' => [null, false],
        'queue worker' => [['artisan', 'queue:work'], true],
        'migrations' => [['artisan', 'migrate'], true],
        'reverb:restart' => [['artisan', 'reverb:restart'], true],
    ]);

    it('warns and carries on without waiting when the database is unreachable', function (?array $argv, bool $runningInConsole) {
        $database = databaseFailsFor(PHP_INT_MAX);
        pretendProcessIs($argv, $runningInConsole);

        bootReverbProviderAgain();

        Sleep::assertNeverSlept();
        expect($database->attempts)->toBe(1)
            ->and(config('reverb.apps.apps'))->toBe([]);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []) => ($context['error'] ?? null)
                === 'SQLSTATE[HY000] [2002] Connection refused (attempt 1)');
        Log::shouldNotHaveReceived('error');
    })->with('other processes');

    it('serves only the loopback app without waiting when there are no active apps', function (?array $argv, bool $runningInConsole) {
        ReverbApp::factory()->inactive()->create();
        pretendProcessIs($argv, $runningInConsole);

        bootReverbProviderAgain();

        Sleep::assertNeverSlept();
        expect(array_column(config('reverb.apps.apps'), 'app_id'))->toBe([LoopbackApp::appId()]);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    })->with('other processes');
});
