<?php
defined( 'ABSPATH' ) || exit;

function fahad_ai_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['fahad_ai_save'] ) && check_admin_referer( 'fahad_ai_settings' ) ) {
		update_option( 'fahad_ai_provider',          sanitize_text_field( wp_unslash( $_POST['provider']          ?? 'anthropic' ) ) );
		update_option( 'fahad_ai_anthropic_api_key', sanitize_text_field( wp_unslash( $_POST['anthropic_api_key'] ?? '' ) ) );
		update_option( 'fahad_ai_anthropic_model',   sanitize_text_field( wp_unslash( $_POST['anthropic_model']   ?? 'claude-haiku-4-5-20251001' ) ) );
		update_option( 'fahad_ai_moonshot_api_key',  sanitize_text_field( wp_unslash( $_POST['moonshot_api_key']  ?? '' ) ) );
		update_option( 'fahad_ai_moonshot_model',    sanitize_text_field( wp_unslash( $_POST['moonshot_model']    ?? 'kimi-k2.6' ) ) );
		update_option( 'fahad_ai_moonshot_region',   'china' === ( $_POST['moonshot_region'] ?? 'global' ) ? 'china' : 'global' );
		update_option( 'fahad_ai_bot_name',          sanitize_text_field( wp_unslash( $_POST['bot_name']          ?? 'Store Assistant' ) ) );
		update_option( 'fahad_ai_greeting',          sanitize_textarea_field( wp_unslash( $_POST['greeting']      ?? 'Hi! How can I help you today?' ) ) );
		update_option( 'fahad_ai_system_prompt',     sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ?? '' ) ) );
		update_option( 'fahad_ai_accent_color',      sanitize_hex_color( wp_unslash( $_POST['accent_color']       ?? '#2563eb' ) ) );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'fahad-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	$provider        = get_option( 'fahad_ai_provider',          'anthropic' );
	$anthropic_key   = get_option( 'fahad_ai_anthropic_api_key', '' );
	$anthropic_model = get_option( 'fahad_ai_anthropic_model',   'claude-haiku-4-5-20251001' );
	$moonshot_key    = get_option( 'fahad_ai_moonshot_api_key',  '' );
	$moonshot_model  = get_option( 'fahad_ai_moonshot_model',    'kimi-k2.6' );
	$moonshot_region = get_option( 'fahad_ai_moonshot_region',   'global' );
	$bot_name        = get_option( 'fahad_ai_bot_name',          'Store Assistant' );
	$greeting        = get_option( 'fahad_ai_greeting',          'Hi! How can I help you today?' );
	$system_prompt   = get_option( 'fahad_ai_system_prompt',     '' );
	$accent_color    = get_option( 'fahad_ai_accent_color',      '#2563eb' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Fahad AI Shopping Assistant Settings', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h1>

		<form method="post">
			<?php wp_nonce_field( 'fahad_ai_settings' ); ?>

			<h2 class="title"><?php esc_html_e( 'Provider', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><label for="provider"><?php esc_html_e( 'AI Provider', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<select id="provider" name="provider">
							<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="moonshot"  <?php selected( $provider, 'moonshot' );  ?>><?php esc_html_e( 'Moonshot AI (Kimi)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>

				<!-- Anthropic fields -->
				<tbody id="fahad-ai-anthropic" style="<?php echo 'anthropic' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="anthropic_api_key" name="anthropic_api_key"
								value="<?php echo esc_attr( $anthropic_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to Anthropic console */
									esc_html__( 'Get your key from %s.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="anthropic_model"><?php esc_html_e( 'Claude Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="anthropic_model" name="anthropic_model">
								<option value="claude-haiku-4-5-20251001" <?php selected( $anthropic_model, 'claude-haiku-4-5-20251001' ); ?>>
									<?php esc_html_e( 'Claude Haiku — Fast & affordable (recommended)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-sonnet-4-6" <?php selected( $anthropic_model, 'claude-sonnet-4-6' ); ?>>
									<?php esc_html_e( 'Claude Sonnet — Balanced performance', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-opus-4-6" <?php selected( $anthropic_model, 'claude-opus-4-6' ); ?>>
									<?php esc_html_e( 'Claude Opus — Most capable', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</tbody>

				<!-- Moonshot / Kimi fields -->
				<tbody id="fahad-ai-moonshot" style="<?php echo 'moonshot' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="moonshot_region"><?php esc_html_e( 'Moonshot Region', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_region" name="moonshot_region">
								<option value="global" <?php selected( $moonshot_region, 'global' ); ?>><?php esc_html_e( 'Global — api.moonshot.ai (rest of world)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
								<option value="china"  <?php selected( $moonshot_region, 'china' );  ?>><?php esc_html_e( 'China — api.moonshot.cn', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose the platform your API key was issued on. Keys and available models are not shared between the global and China platforms.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_api_key"><?php esc_html_e( 'Moonshot API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="moonshot_api_key" name="moonshot_api_key"
								value="<?php echo esc_attr( $moonshot_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: 1: URL to global Moonshot platform, 2: URL to China Moonshot platform */
									esc_html__( 'Get your key from %1$s (global) or %2$s (China).', 'fahad-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://platform.moonshot.ai" target="_blank" rel="noopener">platform.moonshot.ai</a>',
									'<a href="https://platform.moonshot.cn" target="_blank" rel="noopener">platform.moonshot.cn</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_model"><?php esc_html_e( 'Kimi Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_model" name="moonshot_model">
								<optgroup label="<?php esc_attr_e( 'Kimi K2', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="kimi-k2.6"              <?php selected( $moonshot_model, 'kimi-k2.6' );              ?>><?php esc_html_e( 'kimi-k2.6 — Latest, general (recommended)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2.5"              <?php selected( $moonshot_model, 'kimi-k2.5' );              ?>><?php esc_html_e( 'kimi-k2.5 — General', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking-turbo" <?php selected( $moonshot_model, 'kimi-k2-thinking-turbo' ); ?>><?php esc_html_e( 'kimi-k2-thinking-turbo — Reasoning (availability varies by region)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking"       <?php selected( $moonshot_model, 'kimi-k2-thinking' );       ?>><?php esc_html_e( 'kimi-k2-thinking — Reasoning (availability varies by region)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Moonshot V1', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="moonshot-v1-auto"  <?php selected( $moonshot_model, 'moonshot-v1-auto' );  ?>><?php esc_html_e( 'moonshot-v1-auto — Auto context', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="moonshot-v1-8k"    <?php selected( $moonshot_model, 'moonshot-v1-8k' );    ?>>moonshot-v1-8k</option>
									<option value="moonshot-v1-32k"   <?php selected( $moonshot_model, 'moonshot-v1-32k' );   ?>>moonshot-v1-32k</option>
									<option value="moonshot-v1-128k"  <?php selected( $moonshot_model, 'moonshot-v1-128k' );  ?>>moonshot-v1-128k</option>
								</optgroup>
							</select>
							<p class="description">
								<?php esc_html_e( 'Available models depend on your region and key. If you get a "model not found" error, pick another model.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</tbody>

			</table>

			<h2 class="title"><?php esc_html_e( 'Widget', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bot_name"><?php esc_html_e( 'Bot Name', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="bot_name" name="bot_name"
							value="<?php echo esc_attr( $bot_name ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="greeting"><?php esc_html_e( 'Greeting Message', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="greeting" name="greeting" class="large-text" rows="2"><?php echo esc_textarea( $greeting ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="accent_color"><?php esc_html_e( 'Accent Color', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="color" id="accent_color" name="accent_color"
							value="<?php echo esc_attr( $accent_color ); ?>">
					</td>
				</tr>
			</table>

			<h2 class="title">
				<?php esc_html_e( 'System Prompt', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
				<span style="font-weight:400;font-size:13px;"><?php esc_html_e( '(optional)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></span>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="system_prompt"><?php esc_html_e( 'Custom Prompt', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="system_prompt" name="system_prompt" class="large-text" rows="7"><?php echo esc_textarea( $system_prompt ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Leave blank to use the default prompt. Add store policies, shipping info, FAQs, or tone guidelines here.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Save Settings', 'fahad-ai-shopping-assistant-for-woocommerce' ), 'primary', 'fahad_ai_save' ); ?>
		</form>
	</div>
	<?php
}
