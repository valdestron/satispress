<?php
/**
 * Composer packages.json endpoint.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 * @since 0.3.0
 */

declare ( strict_types = 1 );

namespace SatisPress\Route;

use SatisPress\Exception\ExceptionInterface;
use SatisPress\Exception\FileNotFound;
use SatisPress\Capabilities;
use SatisPress\Package;
use SatisPress\PackageManager;
use SatisPress\ReleaseManager;
use SatisPress\VersionParserInterface;
use SatisPress\HTTP\Request;
use WP_Http as HTTP;

/**
 * Class for rendering packages.json for Composer.
 *
 * @since 0.3.0
 */
class Composer implements RouteInterface {
	/**
	 * Package manager.
	 *
	 * @var PackageManager
	 */
	protected $package_manager;

	/**
	 * Release manager.
	 *
	 * @var ReleaseManager
	 */
	protected $release_manager;

	/**
	 * Version parser.
	 *
	 * @var VersionParser
	 */
	protected $version_parser;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param PackageManager         $package_manager Package manager.
	 * @param ReleaseManager         $release_manager Release manager.
	 * @param VersionParserInterface $version_parser  Version parser.
	 */
	public function __construct( PackageManager $package_manager, ReleaseManager $release_manager, VersionParserInterface $version_parser ) {
		$this->package_manager = $package_manager;
		$this->release_manager = $release_manager;
		$this->version_parser  = $version_parser;
	}

	/**
	 * Handle a request to the packages.json endpoint.
	 *
	 * @since 0.3.0
	 *
	 * @param Request $request HTTP request instance.
	 */
	public function handle_request( Request $request ) {
		if ( ! current_user_can( Capabilities::VIEW_PACKAGES ) ) {
			$this->send_403();
		}

		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		$items = $this->get_items();
		echo wp_json_encode( [ 'packages' => $items ] );
		exit;
	}

	/**
	 * Retrieves a collection of packages.
	 *
	 * @since 0.3.0
	 *
	 * @return array
	 */
	public function get_items(): array {
		$items = get_transient( 'satispress_packages' );

		if ( ! $items ) {
			$items    = [];
			$packages = $this->package_manager->get_packages();

			foreach ( $packages as $slug => $package ) {
				if ( ! $package->has_releases() ) {
					continue;
				}

				try {
					$items[ $package->get_package_name() ] = $this->prepare_item_for_response( $package );
				} catch ( FileNotFound $e ) { }
			}

			set_transient( 'satispress_packages', $items, HOUR_IN_SECONDS * 12 );
		}

		return $items;
	}

	/**
	 * Prepare a package for response.
	 *
	 * @param Package $package Package instance.
	 * @return array
	 */
	public function prepare_item_for_response( Package $package ): array {
		$item = [];

		foreach ( $package->get_releases() as $release ) {
			$item[ $release->get_version() ] = [
				'name'               => $package->get_package_name(),
				'version'            => $release->get_version(),
				'version_normalized' => $this->version_parser->normalize( $release->get_version() ),
				'dist'               => [
					'type'   => 'zip',
					'url'    => $release->get_download_url(),
					'shasum' => $this->release_manager->checksum( 'sha1', $release ),
				],
				'require'            => [
					'composer/installers' => '^1.0',
				],
				'type'               => $package->get_type(),
				'authors'            => [
					'name'     => $package->get_author(),
					'homepage' => esc_url( $package->get_author_uri() ),
				],
				'description'        => $package->get_description(),
				'homepage'           => $package->get_homepage(),
			];
		}

		return $item;
	}

	/**
	 * Send a forbidden response.
	 *
	 * @since 0.3.0
	 */
	protected function send_403() {
		status_header( HTTP::FORBIDDEN );
		nocache_headers();
		wp_die( esc_html__( 'Sorry, you are not allowed to view this resource.', 'satispress' ) );
	}
}
