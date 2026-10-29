<?php
/**
 * A bookmaker.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

/**
 * Immutable bookmaker definition.
 *
 * The outbound URL and the logo are editable by administrators, so they are
 * kept separate from the identity fields and replaced through
 * {@see Bookmaker::with_url()} and {@see Bookmaker::with_logo()}.
 */
final class Bookmaker {

	/**
	 * Constructor.
	 *
	 * @param string $id     Machine id, e.g. "bet365".
	 * @param string $name   Display name.
	 * @param string $url    Outbound (affiliate) URL.
	 * @param string $region Market region code, e.g. "uk" or "us"; empty when unknown.
	 * @param string $logo   Logo image URL; empty renders a monogram instead.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $name,
		private readonly string $url = '',
		private readonly string $region = '',
		private readonly string $logo = ''
	) {}

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Outbound URL.
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Market region code.
	 *
	 * @return string
	 */
	public function region(): string {
		return $this->region;
	}

	/**
	 * Logo image URL.
	 *
	 * @return string Empty when neither a bundled icon nor an administrator logo exists.
	 */
	public function logo(): string {
		return $this->logo;
	}

	/**
	 * Returns a copy carrying a different outbound URL.
	 *
	 * @param string $url Replacement URL.
	 * @return self
	 */
	public function with_url( string $url ): self {
		return new self( $this->id, $this->name, $url, $this->region, $this->logo );
	}

	/**
	 * Returns a copy carrying a different logo.
	 *
	 * @param string $logo Replacement logo URL.
	 * @return self
	 */
	public function with_logo( string $logo ): self {
		return new self( $this->id, $this->name, $this->url, $this->region, $logo );
	}

	/**
	 * Array form, used by the REST API and the block editor.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'id'     => $this->id,
			'name'   => $this->name,
			'url'    => $this->url,
			'region' => $this->region,
			'logo'   => $this->logo,
		);
	}
}
