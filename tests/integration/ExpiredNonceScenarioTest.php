<?php declare(strict_types = 1);

namespace UserSwitching\Tests;

use user_switching;
use WP_Session_Tokens;

final class ExpiredNonceScenarioTest extends Test {
	/**
	 * Tests the exact scenario described in the issue:
	 * 1. Two users are switched into the same account
	 * 2. User A clicks Log Out Everywhere Else
	 * 3. User B gets logged out and sent to login screen
	 * 4. User B clicks the Switch Back link and it should work (not show "The link you followed has expired" error)
	 * 
	 * @covers \user_switching::switch_back_url
	 * @covers \user_switching::create_switch_back_nonce
	 * @covers \user_switching::verify_switch_back_nonce
	 */
	public function testExpiredNonceScenarioFix(): void {
		$admin = self::$testers['admin'];
		$user_a = self::$users['author'];
		$user_b = self::$users['editor'];
		$target_user = self::$users['subscriber'];
		
		// Simulate User A switching to target user
		wp_set_current_user( $user_a->ID );
		$user_a_switched = switch_to_user( $target_user->ID );
		self::assertInstanceOf( 'WP_User', $user_a_switched );
		
		// Get User A's switch back link
		$user_a_switch_back_url = user_switching::switch_back_url( $user_a );
		
		// Parse the URL to extract nonce
		$parsed_url = parse_url( $user_a_switch_back_url );
		parse_str( $parsed_url['query'] ?? '', $query_params );
		$user_a_nonce = $query_params['switch_back_nonce'] ?? '';
		
		// Verify User A's nonce was created
		self::assertNotEmpty( $user_a_nonce );
		
		// Simulate User B switching to the same target user
		wp_set_current_user( $user_b->ID );
		$user_b_switched = switch_to_user( $target_user->ID );
		self::assertInstanceOf( 'WP_User', $user_b_switched );
		
		// Get User B's switch back link
		$user_b_switch_back_url = user_switching::switch_back_url( $user_b );
		
		// Parse the URL to extract nonce
		$parsed_url = parse_url( $user_b_switch_back_url );
		parse_str( $parsed_url['query'] ?? '', $query_params );
		$user_b_nonce = $query_params['switch_back_nonce'] ?? '';
		
		// Verify User B's nonce was created
		self::assertNotEmpty( $user_b_nonce );
		
		// Verify both nonces are different (they should be unique)
		self::assertNotEquals( $user_a_nonce, $user_b_nonce );
		
		// Verify both nonces are valid before session invalidation
		wp_set_current_user( $target_user->ID );
		$user_a_verification = user_switching::verify_switch_back_nonce( $user_a_nonce, $user_a->ID, $target_user->ID );
		$user_b_verification = user_switching::verify_switch_back_nonce( $user_b_nonce, $user_b->ID, $target_user->ID );
		
		self::assertTrue( $user_a_verification );
		self::assertTrue( $user_b_verification );
		
		// Simulate "Log Out Everywhere Else" - this invalidates all sessions
		// In the original system, this would break switch back links
		$target_sessions = WP_Session_Tokens::get_instance( $target_user->ID );
		$target_sessions->destroy_all();
		
		// Clear auth cookies to simulate what happens during "Log Out Everywhere Else"
		wp_clear_auth_cookie();
		
		// Verify that our persistent nonces still work after session invalidation
		$user_a_verification_after = user_switching::verify_switch_back_nonce( $user_a_nonce, $user_a->ID, $target_user->ID );
		$user_b_verification_after = user_switching::verify_switch_back_nonce( $user_b_nonce, $user_b->ID, $target_user->ID );
		
		// This is the key test - both nonces should still be valid
		self::assertTrue( $user_a_verification_after, 'User A switch back nonce should remain valid after session invalidation' );
		self::assertTrue( $user_b_verification_after, 'User B switch back nonce should remain valid after session invalidation' );
		
		// Verify nonces are properly isolated - User A's nonce shouldn't work for User B
		$cross_verification = user_switching::verify_switch_back_nonce( $user_a_nonce, $user_b->ID, $target_user->ID );
		self::assertWPError( $cross_verification );
		self::assertSame( 'invalid_switch', $cross_verification->get_error_code() );
		
		// Test that nonces can be consumed (one-time use)
		// For this test, we'll simulate the switch back process by removing the nonce
		$stored_nonces = get_user_meta( $target_user->ID, '_user_switching_switch_backs', true );
		self::assertIsArray( $stored_nonces );
		self::assertArrayHasKey( $user_a_nonce, $stored_nonces );
		self::assertArrayHasKey( $user_b_nonce, $stored_nonces );
		
		// Simulate consuming User A's nonce
		unset( $stored_nonces[ $user_a_nonce ] );
		update_user_meta( $target_user->ID, '_user_switching_switch_backs', $stored_nonces );
		
		// Verify User A's nonce is no longer valid
		$user_a_verification_consumed = user_switching::verify_switch_back_nonce( $user_a_nonce, $user_a->ID, $target_user->ID );
		self::assertWPError( $user_a_verification_consumed );
		self::assertSame( 'invalid_nonce', $user_a_verification_consumed->get_error_code() );
		
		// Verify User B's nonce is still valid
		$user_b_verification_still_valid = user_switching::verify_switch_back_nonce( $user_b_nonce, $user_b->ID, $target_user->ID );
		self::assertTrue( $user_b_verification_still_valid );
	}
	
	/**
	 * Tests that the switch back mechanism works end-to-end with the new nonce system.
	 */
	public function testSwitchBackEndToEnd(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		
		// Start as admin
		wp_set_current_user( $admin->ID );
		
		// Switch to author
		$switched = switch_to_user( $author->ID );
		self::assertInstanceOf( 'WP_User', $switched );
		self::assertSame( $author->ID, get_current_user_id() );
		
		// Generate switch back URL
		$switch_back_url = user_switching::switch_back_url( $admin );
		
		// Parse URL to simulate the switch back request
		$parsed_url = parse_url( $switch_back_url );
		parse_str( $parsed_url['query'] ?? '', $query_params );
		
		// Simulate the switch back request by setting $_REQUEST variables
		$_REQUEST['action'] = $query_params['action'] ?? '';
		$_REQUEST['switch_back_nonce'] = $query_params['switch_back_nonce'] ?? '';
		$_REQUEST['original_user'] = $query_params['original_user'] ?? '';
		
		// Verify the nonce is valid
		$verification = user_switching::verify_switch_back_nonce( 
			$_REQUEST['switch_back_nonce'], 
			intval( $_REQUEST['original_user'] ), 
			get_current_user_id()
		);
		
		self::assertTrue( $verification, 'Switch back nonce should be valid' );
		
		// Clean up the request data
		unset( $_REQUEST['action'], $_REQUEST['switch_back_nonce'], $_REQUEST['original_user'] );
	}
	
	/**
	 * Tests that the cleanup function works correctly.
	 */
	public function testNonceCleanupFunction(): void {
		$admin = self::$testers['admin'];
		$author = self::$users['author'];
		
		wp_set_current_user( $author->ID );
		
		// Create multiple nonces
		$valid_nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		$expired_nonce = user_switching::create_switch_back_nonce( $admin->ID, $author->ID );
		
		// Manually expire one nonce
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		$stored_nonces[ $expired_nonce ]['expires'] = time() - 1;
		update_user_meta( $author->ID, '_user_switching_switch_backs', $stored_nonces );
		
		// Verify both nonces exist initially
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		self::assertArrayHasKey( $valid_nonce, $stored_nonces );
		self::assertArrayHasKey( $expired_nonce, $stored_nonces );
		
		// Run cleanup
		user_switching::cleanup_expired_switch_back_nonces( $author->ID );
		
		// Verify only the valid nonce remains
		$stored_nonces = get_user_meta( $author->ID, '_user_switching_switch_backs', true );
		self::assertArrayHasKey( $valid_nonce, $stored_nonces );
		self::assertArrayNotHasKey( $expired_nonce, $stored_nonces );
	}
}