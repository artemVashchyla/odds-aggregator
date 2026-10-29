<?php
/**
 * Shared scraping behaviour.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\HttpException;

/**
 * Base for providers that read HTML rather than an API.
 *
 * Keeps the fetch-and-parse plumbing in one place so a concrete scraper is
 * reduced to its URLs and its parsing: raw HTML via fetch_html(), or an XPath
 * reader via fetch_dom().
 */
abstract class AbstractScraperProvider extends AbstractProvider {

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http Transport.
	 */
	public function __construct( protected readonly HttpClient $http ) {}

	/**
	 * Fetches a URL and returns the raw response body.
	 *
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Extra request headers.
	 * @return string
	 * @throws ProviderException When the page cannot be fetched or is empty.
	 */
	protected function fetch_html( string $url, array $headers = array() ): string {
		try {
			$html = $this->http->get( $url, $this->id(), $headers );
		} catch ( HttpException $exception ) {
			throw new ProviderException(
				sprintf( '%s could not fetch %s: %s', esc_html( $this->label() ), esc_html( $url ), wp_kses( $exception->getMessage(), array() ) ),
				(int) $exception->getCode(),
				$exception // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- previous exception, not output.
			);
		}

		if ( '' === trim( $html ) ) {
			throw new ProviderException( sprintf( '%s received an empty response.', esc_html( $this->label() ) ) );
		}

		return $html;
	}

	/**
	 * Fetches a URL and returns an XPath reader over the response.
	 *
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Extra request headers.
	 * @return DOMXPath
	 * @throws ProviderException When the page cannot be fetched or parsed.
	 */
	protected function fetch_dom( string $url, array $headers = array() ): DOMXPath {
		$html = $this->fetch_html( $url, $headers );

		$document = new DOMDocument();

		// Third-party markup is never clean; suppress parse notices and carry on.
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $document );
	}

	/**
	 * Trimmed text content of the first node matching a query.
	 *
	 * @param DOMXPath     $xpath   Reader.
	 * @param string       $query   XPath query.
	 * @param DOMNode|null $context Optional context node.
	 * @return string Empty string when nothing matches.
	 */
	protected function text( DOMXPath $xpath, string $query, ?DOMNode $context = null ): string {
		$nodes = null === $context ? $xpath->query( $query ) : $xpath->query( $query, $context );

		if ( ! $nodes instanceof DOMNodeList || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );

		return $node instanceof DOMNode ? trim( (string) $node->textContent ) : '';
	}

	/**
	 * Trimmed value of an attribute on the first node matching a query.
	 *
	 * @param DOMXPath     $xpath     Reader.
	 * @param string       $query     XPath query.
	 * @param string       $attribute Attribute name.
	 * @param DOMNode|null $context   Optional context node.
	 * @return string Empty string when nothing matches.
	 */
	protected function attribute( DOMXPath $xpath, string $query, string $attribute, ?DOMNode $context = null ): string {
		$nodes = null === $context ? $xpath->query( $query ) : $xpath->query( $query, $context );

		if ( ! $nodes instanceof DOMNodeList || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		return trim( $node->getAttribute( $attribute ) );
	}
}
