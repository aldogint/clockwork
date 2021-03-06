<?php namespace Clockwork\Support\Lumen;

use Clockwork\Clockwork;
use Clockwork\DataSource\EloquentDataSource;
use Clockwork\DataSource\LaravelCacheDataSource;
use Clockwork\DataSource\LaravelEventsDataSource;
use Clockwork\DataSource\LumenDataSource;
use Clockwork\DataSource\PhpDataSource;
use Clockwork\DataSource\SwiftDataSource;
use Clockwork\Support\Laravel\ClockworkCleanCommand;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

class ClockworkServiceProvider extends ServiceProvider
{
	public function boot()
	{
		if ($this->isRunningWithFacades() && ! class_exists('Clockwork')) {
			class_alias(\Clockwork\Support\Lumen\Facade::class, 'Clockwork');
		}

		if ($this->app['clockwork.support']->isCollectingData()) {
			$this->listenToEvents();
		}

		if (! $this->app['clockwork.support']->isEnabled()) {
			return; // Clockwork is disabled, don't register the middleware and routes
		}

		$this->registerRoutes();

		// register the Clockwork Web UI routes
		if ($this->app['clockwork.support']->isWebEnabled()) {
			$this->registerWebRoutes();
		}
	}

	protected function listenToEvents()
	{
		if ($this->app['clockwork.support']->isCollectingDatabaseQueries()) {
			$this->app['clockwork.eloquent']->listenToEvents();
		}

		if ($this->app['clockwork.support']->isCollectingEmails()) {
			$this->app->make('clockwork.swift');
		}

		if ($this->app['clockwork.support']->isCollectingCacheStats()) {
			$this->app['clockwork.cache']->listenToEvents();
		}

		if ($this->app['clockwork.support']->isCollectingEvents()) {
			$this->app['clockwork.events']->listenToEvents();
		}
	}

	public function register()
	{
		$this->app->singleton('clockwork.support', function ($app) {
			return new ClockworkSupport($app);
		});

		$this->app->singleton('clockwork.lumen', function ($app) {
			return new LumenDataSource($app);
		});

		$this->app->singleton('clockwork.swift', function ($app) {
			return new SwiftDataSource($app['mailer']->getSwiftMailer());
		});

		$this->app->singleton('clockwork.eloquent', function ($app) {
			return new EloquentDataSource($app['db'], $app['events']);
		});

		$this->app->singleton('clockwork.cache', function ($app) {
			return new LaravelCacheDataSource($app['events']);
		});

		$this->app->singleton('clockwork.events', function ($app) {
			return new LaravelEventsDataSource(
				$app['events'], $app['clockwork.support']->getConfig('ignored_events', [])
			);
		});

		$this->app->singleton('clockwork', function ($app) {
			$clockwork = new Clockwork();

			$clockwork
				->addDataSource(new PhpDataSource())
				->addDataSource($app['clockwork.lumen']);

			if ($app['clockwork.support']->isCollectingDatabaseQueries()) {
				$clockwork->addDataSource($app['clockwork.eloquent']);
			}

			if ($app['clockwork.support']->isCollectingEmails()) {
				$clockwork->addDataSource($app['clockwork.swift']);
			}

			if ($app['clockwork.support']->isCollectingCacheStats()) {
				$clockwork->addDataSource($app['clockwork.cache']);
			}

			if ($app['clockwork.support']->isCollectingEvents()) {
				$clockwork->addDataSource($app['clockwork.events']);
			}

			$clockwork->setStorage($app['clockwork.support']->getStorage());

			return $clockwork;
		});

		$this->app['clockwork.lumen']->listenToEvents();

		// set up aliases for all Clockwork parts so they can be resolved by the IoC container
		$this->app->alias('clockwork.support', ClockworkSupport::class);
		$this->app->alias('clockwork.lumen', LumenDataSource::class);
		$this->app->alias('clockwork.swift', SwiftDataSource::class);
		$this->app->alias('clockwork.eloquent', EloquentDataSource::class);
		$this->app->alias('clockwork', Clockwork::class);

		$this->registerCommands();
		$this->registerMiddleware();

		if ($this->app['clockwork.support']->getConfig('register_helpers', true)) {
			require __DIR__ . '/helpers.php';
		}
	}

	// Register middleware
	public function registerMiddleware()
	{
		$this->app->middleware('Clockwork\Support\Lumen\ClockworkMiddleware', [ $this->app ]);
	}

	/**
	 * Register the artisan commands.
	 */
	public function registerCommands()
	{
		$this->commands([
			\ClockworkCleanCommand::class
		]);
	}

	public function registerRoutes()
	{
		$router = isset($this->app->router) ? $this->app->router : $this->app;

		$router->get('/__clockwork/{id:(?:[0-9-]+|latest)}[/{direction:(?:next|previous)}[/{count:\d+}]]', 'Clockwork\Support\Lumen\Controller@getData');
	}

	public function registerWebRoutes()
	{
		$router = isset($this->app->router) ? $this->app->router : $this->app;

		$router->get('/__clockwork', 'Clockwork\Support\Lumen\Controller@webRedirect');
		$router->get('/__clockwork/app', 'Clockwork\Support\Lumen\Controller@webIndex');
		$router->get('/__clockwork/assets/{path:.+}', 'Clockwork\Support\Lumen\Controller@webAsset');
	}

	public function provides()
	{
		return [ 'clockwork' ];
	}

	protected function isRunningWithFacades()
	{
		return Facade::getFacadeApplication() !== null;
	}
}
