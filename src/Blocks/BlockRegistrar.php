<?php
/**
 * Block registration.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Blocks;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Odds\OddsRepository;

/**
 * Registers the dynamic odds table block.
 *
 * Metadata comes from the compiled block.json, so attributes are declared once
 * and shared by the editor and the server renderer rather than duplicated.
 */
final class BlockRegistrar {

	/**
	 * Constructor.
	 *
	 * @param OddsRepository    $repository Cached odds access.
	 * @param Settings          $settings   Plugin settings.
	 * @param BookmakerRegistry $bookmakers Bookmaker catalogue.
	 * @param MarketRegistry    $markets    Market catalogue.
	 */
	public function __construct(
		private readonly OddsRepository $repository,
		private readonly Settings $settings,
		private readonly BookmakerRegistry $bookmakers,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Hooks block registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registers the block type.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$metadata = ODDS_AGGREGATOR_PATH . 'build/odds-table';

		if ( ! file_exists( $metadata . '/block.json' ) ) {
			// Not built yet; surface it in the admin rather than failing silently.
			add_action( 'admin_notices', array( $this, 'render_build_notice' ) );

			return;
		}

		register_block_type(
			$metadata,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes ): string {
		$renderer = new OddsTableRenderer(
			$this->repository,
			$this->settings,
			$this->bookmakers,
			$this->markets
		);

		return $renderer->render( $attributes );
	}

	/**
	 * Tells administrators the block assets are missing.
	 *
	 * @return void
	 */
	public function render_build_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html__(
				'Odds Aggregator: block assets are missing. Run "npm ci && npm run build" in the plugin directory.',
				'odds-aggregator'
			)
			. '</p></div>';
	}
}
