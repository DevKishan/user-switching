<?php declare(strict_types = 1);

namespace UserSwitching\Tests;

use user_switching;
use WP_Session_Tokens;

final class SwitchBackNonceTest extends Test {
	/**
	 * @covers \user_switching::create_switch_back_nonce
	 * @covers \user_switching::verify_switch_back_nonce
	 */
	public function testSwitchBackNoncePersistence(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		
		wp_set_current_user( $author->ID );
		
		// Create a switch back nonce
		$nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		
		// Verify the nonce was created
		self::assertNotEmpty( $nonce );
		
		// Verify the nonce is valid
		$verification = user_switching::verify_switch_back_nonce( $nonce, $admin->ID, $author->ID );
		self::assertTrue( $verification );
		
		// Verify the nonce data is stored correctly
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		self::assertIsArray( $stored_nonces );
		self::assertArrayHasKey( $nonce, $stored_nonces );
		self::assertSame( $admin->ID, $stored_nonces[ $nonce ]['original_user'] );
		self::assertSame( $author->ID, $stored_nonces[ $nonce ]['current_user'] );
	}
	
	/**
	 * @covers \user_switching::verify_switch_back_nonce
	 */
	public function testSwitchBackNonceValidation(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		$editor = self::$users['editor'];
		
		wp_set_current_user( $author->ID );
		
		// Create a switch back nonce
		$nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		
		// Test with wrong original user
		$verification = user_switching::verify_switch_back_nonce( $nonce, $editor->ID, $author->ID );
		self::assertWPError( $verification );
		self::assertSame( 'invalid_switch', $verification->get_error_code() );
		
		// Test with wrong current user
		$verification = user_switching::verify_switch_back_nonce( $nonce, $admin->ID, $editor->ID );
		self::assertWPError( $verification );
		self::assertSame( 'invalid_switch', $verification->get_error_code() );
		
		// Test with invalid nonce
		$verification = user_switching::verify_switch_back_nonce( 'invalid_nonce', $admin->ID, $author->ID );
		self::assertWPError( $verification );
		self::assertSame( 'invalid_nonce', $verification->get_error_code() );
	}
	
	/**
	 * @covers \user_switching::verify_switch_back_nonce
	 */
	public function testSwitchBackNonceExpiration(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		
		wp_set_current_user( $author->ID );
		
		// Create a switch back nonce
		$nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		
		// Manually expire the nonce
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		$stored_nonces[ $nonce ]['expires'] = time() - 1;
		update_user_meta( $author->ID, '_user_switching_switch_backs', $stored_nonces );
		
		// Verify the nonce is expired
		$verification = user_switching::verify_switch_back_nonce( $nonce, $admin->ID, $author->ID );
		self::assertWPError( $verification );
		self::assertSame( 'expired_nonce', $verification->get_error_code() );
		
		// Verify the expired nonce was cleaned up
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		self::assertArrayNotHasKey( $nonce, $stored_nonces );
	}
	
	/**
	 * @covers \user_switching::cleanup_expired_switch_back_nonces
	 */
	public function testSwitchBackNonceCleanup(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		
		wp_set_current_user( $author->ID );
		
		// Create multiple switch back nonces
		$valid_nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		$expired_nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		
		// Manually expire one nonce
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		$stored_nonces[ $expired_nonce ]['expires'] = time() - 1;
		update_user_meta( $author->ID, '_user_switching_switch_backs', $stored_nonces );
		
		// Run cleanup
		user_switching::cleanup_expired_switch_back_nonces( $author->ID );
		
		// Verify only the valid nonce remains
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		self::assertArrayHasKey( $valid_nonce, $stored_nonces );
		self::assertArrayNotHasKey( $expired_nonce, $stored_nonces );
	}
	
	/**
	 * @covers \user_switching::switch_back_url
	 */
	public function testSwitchBackUrlGeneration(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		
		wp_set_current_user( $author->ID );
		
		// Generate a switch back URL
		$url = user_switching::switch_back_url( $admin );
		
		// Verify the URL contains the expected parameters
		self::assertStringContainsString( 'action=switch_to_olduser', $url );
		self::assertStringContainsString( 'switch_back_nonce=', $url );
		self::assertStringContainsString( 'original_user=' . $admin->ID, $url );
		
		// Parse the URL to get the nonce
		$parsed_url = parse_url( $url );
		parse_str( $parsed_url['query'] ?? '', $query_params );
		
		self::assertArrayHasKey( 'switch_back_nonce', $query_params );
		self::assertArrayHasKey( 'original_user', $query_params );
		
		// Verify the nonce was stored
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		self::assertArrayHasKey( $query_params['switch_back_nonce'], $stored_nonces );
	}
	
	/**
	 * Tests that switch back nonces survive session invalidation scenarios.
	 * This simulates the "Log Out Everywhere Else" scenario.
	 */
	public function testSwitchBackNonceSurvivesSessionInvalidation(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		
		wp_set_current_user( $author->ID );
		
		// Create a switch back nonce
		$nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		
		// Simulate session invalidation (like "Log Out Everywhere Else")
		$sessions = WP_Session_Tokens::get_instance( $author->ID );
		$sessions->destroy_all();
		
		// Clear WordPress nonces (simulating what happens during session invalidation)
		wp_clear_auth_cookie();
		
		// Verify the persistent nonce is still valid
		$verification = user_switching::verify_switch_back_nonce( $nonce, $admin->ID, $author->ID );
		self::assertTrue( $verification );
		
		// Verify the nonce data is still stored
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		self::assertArrayHasKey( $nonce, $stored_nonces );
	}
}