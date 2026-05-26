<?php
/**
 * Minimal stubs for WordPress\AiClient classes used in unit tests.
 * These are not loaded in integration tests — real WP core classes are used there.
 */

namespace WordPress\AiClient\Providers\Http\Contracts;

use WordPress\AiClient\Providers\Http\DTO\Request;

if ( ! interface_exists( RequestAuthenticationInterface::class ) ) {
	interface RequestAuthenticationInterface {
		public function authenticateRequest( Request $request ): Request;
	}
}

namespace WordPress\AiClient\Providers\Http\DTO;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;

if ( ! class_exists( Request::class ) ) {
	class Request {
		private array $headers = [];

		public function withHeader( string $name, string $value ): static {
			$clone                              = clone $this;
			$clone->headers[ strtolower( $name ) ] = $value;
			return $clone;
		}

		public function getHeaderLine( string $name ): string {
			return $this->headers[ strtolower( $name ) ] ?? '';
		}

		public function hasHeader( string $name ): bool {
			return isset( $this->headers[ strtolower( $name ) ] );
		}
	}
}

if ( ! class_exists( ApiKeyRequestAuthentication::class ) ) {
	class ApiKeyRequestAuthentication implements RequestAuthenticationInterface {
		protected string $apiKey;

		public function __construct( string $apiKey ) {
			$this->apiKey = $apiKey;
		}

		public function getApiKey(): string {
			return $this->apiKey;
		}

		public function authenticateRequest( Request $request ): Request {
			return $request->withHeader( 'Authorization', 'Bearer ' . $this->apiKey );
		}
	}
}
