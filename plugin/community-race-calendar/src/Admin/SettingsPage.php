<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Admin;

use CommunityRaceCalendar\Access\AccessGate;
use CommunityRaceCalendar\Database\Migrator;
use CommunityRaceCalendar\Geocoding\GeocodingProviderInterface;
use CommunityRaceCalendar\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    private const PAGE_SLUG = 'community-race-calendar';

    public function __construct(
        private AccessGate $gate,
        private GeocodingProviderInterface $geocoding
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'dependencyNotice']);
        add_action('admin_post_crcal_create_calendar_page', [$this, 'createCalendarPage']);
        add_action('admin_post_crcal_save_geoapify_key', [$this, 'saveGeoapifyKey']);
        add_action('admin_post_crcal_test_geoapify_key', [$this, 'testGeoapifyKey']);
    }

    public function menu(): void
    {
        add_options_page(
            __('Calendrier communautaire', 'community-race-calendar'),
            __('Calendrier communautaire', 'community-race-calendar'),
            AccessGate::MANAGE_CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function settings(): void
    {
        register_setting('community_race_calendar', Plugin::SETTINGS_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeSubmittedSettings'],
            'default' => Plugin::defaults(),
        ]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function sanitizeSubmittedSettings(array $input): array
    {
        $settings = Plugin::sanitizeSettings($input);
        $colorLabels = [
            'brand_color' => __('couleur principale', 'community-race-calendar'),
            'background_color' => __('arrière-plan', 'community-race-calendar'),
            'surface_color' => __('cartes et panneaux', 'community-race-calendar'),
        ];
        foreach ($colorLabels as $key => $label) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $requested = sanitize_hex_color((string) $input[$key]);
            if (!is_string($requested) || strtolower($requested) !== strtolower((string) $settings[$key])) {
                add_settings_error(
                    Plugin::SETTINGS_OPTION,
                    'crcal_color_contrast_' . $key,
                    sprintf(
                        /* translators: %s: color setting label, for example the main color. */
                        __('La %s choisie est invalide ou manque de contraste. Une valeur sûre a été conservée ; choisissez une couleur respectant un contraste d’au moins 4,5:1.', 'community-race-calendar'),
                        $label
                    ),
                    'error'
                );
            }
        }
        $designLabels = [
            'text_color' => __('couleur du texte', 'community-race-calendar'),
            'font_family' => __('police', 'community-race-calendar'),
            'content_max_width' => __('largeur maximale', 'community-race-calendar'),
        ];
        foreach ($designLabels as $key => $label) {
            $requested = trim((string) ($input[$key] ?? ''));
            if (!array_key_exists($key, $input) || $requested === '') {
                continue;
            }
            $accepted = match ($key) {
                'font_family' => $settings[$key] !== '',
                'content_max_width' => Plugin::sanitizeContentWidth($requested, '') === $settings[$key],
                default => strtolower($requested) === strtolower((string) $settings[$key]),
            };
            if (!$accepted) {
                add_settings_error(
                    Plugin::SETTINGS_OPTION,
                    'crcal_design_invalid_' . $key,
                    sprintf(
                        /* translators: %s: design setting label, for example the font. */
                        __('La valeur saisie pour %s est invalide ou hors limites. La valeur par défaut a été conservée.', 'community-race-calendar'),
                        $label
                    ),
                    'error'
                );
            }
        }
        $wasEnabled = (bool) (Plugin::settings()['delete_data_on_uninstall'] ?? false);
        $confirmation = sanitize_text_field((string) ($input['delete_data_confirmation'] ?? ''));
        if (!$wasEnabled && $settings['delete_data_on_uninstall'] && $confirmation !== 'SUPPRIMER') {
            $settings['delete_data_on_uninstall'] = false;
            add_settings_error(
                Plugin::SETTINGS_OPTION,
                'crcal_uninstall_confirmation_required',
                __('La suppression définitive reste désactivée : saisissez SUPPRIMER pour confirmer.', 'community-race-calendar'),
                'error'
            );
        }
        return $settings;
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }
        $stylePath = CRCAL_PLUGIN_DIR . 'assets/admin.css';
        $scriptPath = CRCAL_PLUGIN_DIR . 'assets/admin.js';
        if (is_readable($stylePath)) {
            wp_enqueue_style('community-race-calendar-admin', CRCAL_PLUGIN_URL . 'assets/admin.css', [], (string) filemtime($stylePath));
        }
        if (is_readable($scriptPath)) {
            wp_enqueue_script('community-race-calendar-admin', CRCAL_PLUGIN_URL . 'assets/admin.js', [], (string) filemtime($scriptPath), true);
        }
    }

    public function dependencyNotice(): void
    {
        if ($this->gate->provider()->isAvailable() || !$this->gate->canManage()) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Calendrier communautaire :', 'community-race-calendar') . '</strong> ' . esc_html($this->gate->provider()->unavailableReason()) . '</p></div>';
    }

    public function createCalendarPage(): void
    {
        $this->authorize('crcal_create_calendar_page');
        $settings = Plugin::settings();
        $pageId = absint($settings['calendar_page_id'] ?? 0);
        if (!$pageId || get_post_status($pageId) === false) {
            $pageId = wp_insert_post([
                'post_title' => $settings['brand_name'],
                'post_name' => 'calendrier',
                'post_content' => '[community_race_calendar]',
                'post_status' => 'draft',
                'post_type' => 'page',
            ], true);
            if (is_wp_error($pageId)) {
                wp_die(esc_html($pageId->get_error_message()));
            }
            $settings['calendar_page_id'] = $pageId;
            update_option(Plugin::SETTINGS_OPTION, $settings, false);
        }
        wp_safe_redirect(admin_url('post.php?action=edit&post=' . absint($pageId)));
        exit;
    }

    public function saveGeoapifyKey(): void
    {
        $this->authorize('crcal_save_geoapify_key');

        $remove = isset($_POST['remove_geoapify_api_key']) && sanitize_key(wp_unslash((string) $_POST['remove_geoapify_api_key'])) === '1';
        if ($remove) {
            delete_option(Plugin::GEOAPIFY_API_KEY_OPTION);
            delete_transient('crcal_geoapify_operational');
            $this->redirectAfterGeoapifyUpdate('removed');
        }

        $submitted = isset($_POST['geoapify_api_key']) ? wp_unslash((string) $_POST['geoapify_api_key']) : '';
        $apiKey = Plugin::sanitizeGeoapifyApiKey($submitted);
        if ($apiKey === '') {
            $this->redirectAfterGeoapifyUpdate('invalid');
        }

        $previous = get_option(Plugin::GEOAPIFY_API_KEY_OPTION, null);
        update_option(Plugin::GEOAPIFY_API_KEY_OPTION, $apiKey, false);
        delete_transient('crcal_geoapify_operational');
        $verification = $this->geocoding->verify();

        if (is_wp_error($verification) && $verification->get_error_code() === 'crcal_geocoding_key_rejected') {
            if ($previous === null) {
                delete_option(Plugin::GEOAPIFY_API_KEY_OPTION);
            } else {
                update_option(Plugin::GEOAPIFY_API_KEY_OPTION, (string) $previous, false);
            }
            delete_transient('crcal_geoapify_operational');
            $this->redirectAfterGeoapifyUpdate('rejected');
        }

        $this->redirectAfterGeoapifyUpdate(is_wp_error($verification) ? 'saved_unverified' : 'saved');
    }

    public function testGeoapifyKey(): void
    {
        $this->authorize('crcal_test_geoapify_key');
        if (!$this->geocoding->isAvailable()) {
            $this->redirectAfterGeoapifyUpdate('missing');
        }
        delete_transient('crcal_geoapify_operational');
        $verification = $this->geocoding->verify();
        $this->redirectAfterGeoapifyUpdate(is_wp_error($verification) ? 'test_failed' : 'test_success');
    }

    private function authorize(string $nonceAction): void
    {
        if (!current_user_can(AccessGate::MANAGE_CAPABILITY)) {
            wp_die(esc_html__('Action interdite.', 'community-race-calendar'), '', ['response' => 403]);
        }
        check_admin_referer($nonceAction);
    }

    private function redirectAfterGeoapifyUpdate(string $status): never
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE_SLUG,
            'crcal_geoapify_status' => $status,
        ], admin_url('options-general.php')) . '#crc-geoapify-settings');
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(AccessGate::MANAGE_CAPABILITY)) {
            return;
        }
        $settings = Plugin::settings();
        $plans = function_exists('wc_memberships_get_membership_plans') ? wc_memberships_get_membership_plans() : [];
        $geoapifyStatus = isset($_GET['crcal_geoapify_status']) ? sanitize_key(wp_unslash((string) $_GET['crcal_geoapify_status'])) : '';
        $constantGeoapifyKey = defined('CRCAL_GEOAPIFY_API_KEY') && Plugin::sanitizeGeoapifyApiKey((string) CRCAL_GEOAPIFY_API_KEY) !== '';
        $storedGeoapifyKey = Plugin::geoapifyApiKey() !== '';
        $effectiveGeoapifyKey = $storedGeoapifyKey || $constantGeoapifyKey;
        $operational = method_exists($this->geocoding, 'operationalStatus') ? $this->geocoding->operationalStatus() : null;
        $calendarPageId = absint($settings['calendar_page_id'] ?? 0);
        $calendarPageExists = $calendarPageId > 0 && get_post_status($calendarPageId) !== false;
        $showPlanSelector = count($plans) > 1;
        ?>
        <div class="wrap crc-settings">
            <header class="crc-settings__header">
                <div>
                    <h1><?php esc_html_e('Réglages du calendrier', 'community-race-calendar'); ?></h1>
                    <p><?php esc_html_e('Configurez les textes, l’ajout des courses, l’apparence et la recherche de lieux.', 'community-race-calendar'); ?></p>
                </div>
                <?php if ($calendarPageExists) : ?>
                    <a class="button" href="<?php echo esc_url(get_permalink($calendarPageId)); ?>" target="_blank" rel="noreferrer"><?php esc_html_e('Voir le calendrier', 'community-race-calendar'); ?></a>
                <?php endif; ?>
            </header>

            <?php $this->renderGeoapifyNotice($geoapifyStatus); ?>

            <details class="crc-settings__technical">
                <summary><?php esc_html_e('Informations techniques', 'community-race-calendar'); ?></summary>
                <dl class="crc-settings__summary" aria-label="<?php esc_attr_e('État du calendrier', 'community-race-calendar'); ?>">
                    <div><dt><?php esc_html_e('Extension', 'community-race-calendar'); ?></dt><dd><?php echo esc_html(CRCAL_VERSION); ?></dd></div>
                    <div><dt><?php esc_html_e('Base de données', 'community-race-calendar'); ?></dt><dd><?php echo get_option(Migrator::OPTION) === CRCAL_SCHEMA_VERSION ? esc_html__('À jour', 'community-race-calendar') : esc_html__('À vérifier', 'community-race-calendar'); ?></dd></div>
                    <div><dt><?php esc_html_e('Contrôle des accès', 'community-race-calendar'); ?></dt><dd><?php echo esc_html($this->gate->provider()->label()); ?></dd></div>
                    <div><dt><?php esc_html_e('Recherche de lieux', 'community-race-calendar'); ?></dt><dd><?php echo $operational === true ? esc_html__('Opérationnelle', 'community-race-calendar') : ($effectiveGeoapifyKey ? esc_html__('À tester', 'community-race-calendar') : esc_html__('Non configurée', 'community-race-calendar')); ?></dd></div>
                </dl>
            </details>

            <form method="post" action="options.php" class="crc-settings__form">
                <?php settings_fields('community_race_calendar'); ?>
                <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[calendar_page_id]" value="<?php echo esc_attr((string) $calendarPageId); ?>">
                <?php if (!$showPlanSelector) : ?>
                    <?php foreach ($settings['membership_plan_ids'] as $planId) : ?>
                        <input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[membership_plan_ids][]" value="<?php echo esc_attr((string) $planId); ?>">
                    <?php endforeach; ?>
                <?php endif; ?>

                <section class="crc-settings__section" aria-labelledby="crc-general-title">
                    <div class="crc-settings__section-heading">
                        <h2 id="crc-general-title"><?php esc_html_e('Informations générales', 'community-race-calendar'); ?></h2>
                        <p><?php esc_html_e('Les textes affichés en haut du calendrier.', 'community-race-calendar'); ?></p>
                    </div>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="crc-brand-name"><?php esc_html_e('Nom du calendrier', 'community-race-calendar'); ?></label></th><td><input id="crc-brand-name" class="regular-text" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[brand_name]" value="<?php echo esc_attr($settings['brand_name']); ?>" required></td></tr>
                        <tr><th><label for="crc-tagline"><?php esc_html_e('Texte d’introduction', 'community-race-calendar'); ?></label></th><td><input id="crc-tagline" class="regular-text" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[tagline]" value="<?php echo esc_attr($settings['tagline']); ?>"></td></tr>
                    </table>
                </section>

                <section class="crc-settings__section" aria-labelledby="crc-access-title">
                    <div class="crc-settings__section-heading">
                        <h2 id="crc-access-title"><?php esc_html_e('Accès et adhésions', 'community-race-calendar'); ?></h2>
                        <p><?php esc_html_e('Configurez la redirection proposée aux utilisateurs sans adhésion active.', 'community-race-calendar'); ?></p>
                    </div>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="crc-membership-url"><?php esc_html_e('Page d’adhésion', 'community-race-calendar'); ?></label></th>
                            <td>
                                <input id="crc-membership-url" class="regular-text" type="url" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[membership_url]" value="<?php echo esc_attr($settings['membership_url']); ?>" aria-describedby="crc-membership-url-description" required>
                                <p id="crc-membership-url-description" class="description"><?php esc_html_e('Le bouton « Gérer mon adhésion » dirige vers cette page.', 'community-race-calendar'); ?></p>
                            </td>
                        </tr>
                        <?php if ($showPlanSelector) : ?>
                        <tr><th><?php esc_html_e('Adhésions autorisées', 'community-race-calendar'); ?></th><td>
                            <?php foreach ($plans as $plan) : $id = (int) $plan->get_id(); ?>
                                <label class="crc-settings__check"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[membership_plan_ids][]" value="<?php echo esc_attr((string) $id); ?>" <?php checked(in_array($id, $settings['membership_plan_ids'], true)); ?>> <?php echo esc_html($plan->get_name()); ?></label>
                            <?php endforeach; ?>
                        </td></tr>
                        <?php endif; ?>
                    </table>
                    <label class="crc-settings__toggle"><input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[members_can_create]" value="0"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[members_can_create]" value="1" <?php checked($settings['members_can_create']); ?>> <?php esc_html_e('Autoriser les membres actifs à ajouter des courses', 'community-race-calendar'); ?></label>
                </section>

                <section class="crc-settings__section" aria-labelledby="crc-appearance-title">
                    <div class="crc-settings__section-heading">
                        <h2 id="crc-appearance-title"><?php esc_html_e('Apparence', 'community-race-calendar'); ?></h2>
                        <p><?php esc_html_e('Adaptez les couleurs à l’identité du site.', 'community-race-calendar'); ?></p>
                    </div>
                    <div class="crc-settings__colors">
                        <label><span><?php esc_html_e('Couleur principale', 'community-race-calendar'); ?></span><input type="color" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[brand_color]" value="<?php echo esc_attr($settings['brand_color']); ?>"></label>
                        <label><span><?php esc_html_e('Arrière-plan', 'community-race-calendar'); ?></span><input type="color" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[background_color]" value="<?php echo esc_attr($settings['background_color']); ?>"></label>
                        <label><span><?php esc_html_e('Cartes et panneaux', 'community-race-calendar'); ?></span><input type="color" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[surface_color]" value="<?php echo esc_attr($settings['surface_color']); ?>"></label>
                    </div>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="crc-text-color"><?php esc_html_e('Couleur du texte', 'community-race-calendar'); ?></label></th>
                            <td><input id="crc-text-color" type="color" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[text_color]" value="<?php echo esc_attr($settings['text_color']); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="crc-font-family"><?php esc_html_e('Police', 'community-race-calendar'); ?></label></th>
                            <td>
                                <input id="crc-font-family" class="regular-text" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[font_family]" value="<?php echo esc_attr($settings['font_family']); ?>" maxlength="200" autocomplete="off" spellcheck="false" placeholder="Georgia, serif" aria-describedby="crc-font-family-description">
                                <p id="crc-font-family-description" class="description"><?php esc_html_e('Liste de familles séparées par des virgules (lettres, chiffres, espaces et tirets ; guillemets simples ou doubles autour d’un nom). Aucune police n’est fournie par l’extension : elle doit être disponible sur le site. Laissez vide pour reprendre la police du thème.', 'community-race-calendar'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="crc-content-max-width"><?php esc_html_e('Largeur maximale du contenu', 'community-race-calendar'); ?></label></th>
                            <td>
                                <input id="crc-content-max-width" class="small-text" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[content_max_width]" value="<?php echo esc_attr($settings['content_max_width']); ?>" maxlength="9" autocomplete="off" spellcheck="false" aria-describedby="crc-content-max-width-description">
                                <p id="crc-content-max-width-description" class="description"><?php esc_html_e('Un nombre suivi de px (320 à 2400), rem (20 à 150) ou % (50 à 100), par exemple 1200px.', 'community-race-calendar'); ?></p>
                            </td>
                        </tr>
                    </table>
                </section>

                <section class="crc-settings__section" aria-labelledby="crc-map-title">
                    <div class="crc-settings__section-heading">
                        <h2 id="crc-map-title"><?php esc_html_e('Carte', 'community-race-calendar'); ?></h2>
                        <p><?php esc_html_e('Le fond de carte est fourni par un service externe.', 'community-race-calendar'); ?></p>
                    </div>
                    <label class="crc-settings__toggle"><input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[map_enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[map_enabled]" value="1" aria-describedby="crc-map-description" <?php checked($settings['map_enabled']); ?>> <?php esc_html_e('Afficher la carte des courses (fond de carte OpenFreeMap)', 'community-race-calendar'); ?></label>
                    <p id="crc-map-description" class="description">
                        <?php esc_html_e('Lorsque cette case est cochée, le navigateur de chaque membre qui ouvre la vue Carte, ou l’aperçu du lieu de départ dans le formulaire de course, télécharge les tuiles de carte depuis tiles.openfreemap.org, un service tiers. Ce service reçoit l’adresse IP du visiteur, les informations habituelles de son navigateur et la zone de carte demandée. Aucune donnée du calendrier ni aucun identifiant de membre ne lui est transmis. Lorsque la case est décochée, aucune requête n’est envoyée à ce service et un message remplace la carte ; le calendrier et les autres vues restent utilisables.', 'community-race-calendar'); ?>
                        <a href="https://openfreemap.org/tos/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Conditions d’utilisation', 'community-race-calendar'); ?></a>
                        &middot;
                        <a href="https://openfreemap.org/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Politique de confidentialité', 'community-race-calendar'); ?></a>
                    </p>
                </section>

                <section class="crc-settings__section" aria-labelledby="crc-names-title">
                    <div class="crc-settings__section-heading">
                        <h2 id="crc-names-title"><?php esc_html_e('Noms des membres', 'community-race-calendar'); ?></h2>
                        <p><?php esc_html_e('Ce que les membres voient des autres membres.', 'community-race-calendar'); ?></p>
                    </div>
                    <label class="crc-settings__toggle"><input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[show_member_names]" value="0"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[show_member_names]" value="1" aria-describedby="crc-names-description" <?php checked($settings['show_member_names']); ?>> <?php esc_html_e('Afficher le prénom et le nom des membres', 'community-race-calendar'); ?></label>
                    <p id="crc-names-description" class="description"><?php esc_html_e('Cochée : tous les membres actifs voient le prénom et le nom de chaque membre (profil WordPress, sinon nom de facturation WooCommerce, sinon nom public) dans les inscriptions, commentaires, covoiturages, résultats, revue de presse et bilan, et peuvent le rechercher par prénom ou nom de famille. Décochée : seul le nom public choisi par chaque membre est affiché et recherché. Dans les deux cas, l’identifiant de connexion n’est jamais recherché ni les adresses e-mail affichées en entier.', 'community-race-calendar'); ?></p>
                </section>

                <details class="crc-settings__advanced crc-settings__danger">
                    <summary><?php esc_html_e('Zone dangereuse', 'community-race-calendar'); ?></summary>
                    <label><input type="hidden" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[delete_data_on_uninstall]" value="0"><input type="checkbox" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[delete_data_on_uninstall]" value="1" <?php checked($settings['delete_data_on_uninstall']); ?>> <?php esc_html_e('Supprimer définitivement toutes les données du calendrier lors de la désinstallation de l’extension', 'community-race-calendar'); ?></label>
                    <?php if (!$settings['delete_data_on_uninstall']) : ?>
                        <label for="crc-delete-data-confirmation"><?php esc_html_e('Pour activer ce danger, saisissez SUPPRIMER', 'community-race-calendar'); ?></label>
                        <input id="crc-delete-data-confirmation" class="regular-text" name="<?php echo esc_attr(Plugin::SETTINGS_OPTION); ?>[delete_data_confirmation]" value="" autocomplete="off" spellcheck="false">
                    <?php endif; ?>
                </details>

                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer les réglages', 'community-race-calendar'); ?></button></p>
            </form>

            <section id="crc-geoapify-settings" class="crc-settings__section crc-settings__section--standalone" aria-labelledby="crc-geoapify-title">
                <div class="crc-settings__section-heading crc-settings__section-heading--status">
                    <div>
                        <h2 id="crc-geoapify-title"><?php esc_html_e('Recherche de lieux avec Geoapify', 'community-race-calendar'); ?></h2>
                        <p><?php esc_html_e('Collez une clé API pour proposer automatiquement les villes et placer le départ sur la carte.', 'community-race-calendar'); ?></p>
                    </div>
                    <span class="crc-settings__status <?php echo $operational === true ? 'is-success' : ($effectiveGeoapifyKey ? 'is-warning' : 'is-neutral'); ?>">
                        <?php echo $operational === true ? esc_html__('Connexion validée', 'community-race-calendar') : ($effectiveGeoapifyKey ? esc_html__('Clé à tester', 'community-race-calendar') : esc_html__('Aucune clé', 'community-race-calendar')); ?>
                    </span>
                </div>

                <?php if ($storedGeoapifyKey) : ?>
                    <p class="crc-settings__source"><?php esc_html_e('Une clé est enregistrée dans WordPress. Elle n’est jamais réaffichée ni envoyée au navigateur.', 'community-race-calendar'); ?></p>
                <?php elseif ($constantGeoapifyKey) : ?>
                    <p class="crc-settings__source"><?php esc_html_e('Une ancienne clé de secours existe dans la configuration du serveur. La clé collée ici la remplacera pour le calendrier.', 'community-race-calendar'); ?></p>
                <?php else : ?>
                    <p class="crc-settings__source"><?php esc_html_e('La clé sera conservée uniquement côté serveur et masquée après son enregistrement.', 'community-race-calendar'); ?></p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crc-settings__key-form">
                    <input type="hidden" name="action" value="crcal_save_geoapify_key">
                    <?php wp_nonce_field('crcal_save_geoapify_key'); ?>
                    <label for="crc-geoapify-api-key"><?php echo $storedGeoapifyKey ? esc_html__('Remplacer la clé API', 'community-race-calendar') : esc_html__('Clé API Geoapify', 'community-race-calendar'); ?></label>
                    <div class="crc-settings__secret">
                        <input id="crc-geoapify-api-key" type="password" class="regular-text" name="geoapify_api_key" value="" minlength="20" maxlength="200" pattern="[A-Za-z0-9_-]{20,200}" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr($storedGeoapifyKey ? __('Collez une nouvelle clé', 'community-race-calendar') : __('Collez la clé créée dans Geoapify', 'community-race-calendar')); ?>" <?php echo $effectiveGeoapifyKey ? '' : 'required'; ?> aria-describedby="crc-geoapify-help">
                        <button type="button" class="button" data-crc-toggle-secret aria-controls="crc-geoapify-api-key" aria-pressed="false"><?php esc_html_e('Afficher', 'community-race-calendar'); ?></button>
                    </div>
                    <p id="crc-geoapify-help" class="description"><?php esc_html_e('L’enregistrement vérifie automatiquement la clé auprès de Geoapify.', 'community-race-calendar'); ?></p>
                    <?php if ($storedGeoapifyKey) : ?>
                        <label class="crc-settings__remove-key"><input type="checkbox" name="remove_geoapify_api_key" value="1"> <?php echo $constantGeoapifyKey ? esc_html__('Supprimer la clé WordPress et revenir à la clé de secours', 'community-race-calendar') : esc_html__('Supprimer la clé enregistrée', 'community-race-calendar'); ?></label>
                    <?php endif; ?>
                    <div class="crc-settings__actions">
                        <button type="submit" class="button button-primary"><?php echo $storedGeoapifyKey ? esc_html__('Remplacer et vérifier la clé', 'community-race-calendar') : esc_html__('Enregistrer et vérifier la clé', 'community-race-calendar'); ?></button>
                    </div>
                </form>

                <?php if ($effectiveGeoapifyKey) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crc-settings__test-form">
                        <input type="hidden" name="action" value="crcal_test_geoapify_key">
                        <?php wp_nonce_field('crcal_test_geoapify_key'); ?>
                        <button type="submit" class="button"><?php esc_html_e('Tester la connexion actuelle', 'community-race-calendar'); ?></button>
                    </form>
                <?php endif; ?>
            </section>

            <?php if (!$calendarPageExists) : ?>
            <section class="crc-settings__section crc-settings__section--standalone" aria-labelledby="crc-page-title">
                <div class="crc-settings__section-heading">
                    <h2 id="crc-page-title"><?php esc_html_e('Page du calendrier', 'community-race-calendar'); ?></h2>
                    <p><?php esc_html_e('Aucune page calendrier n’est actuellement associée à l’extension.', 'community-race-calendar'); ?></p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="crcal_create_calendar_page">
                    <?php wp_nonce_field('crcal_create_calendar_page'); ?>
                    <button type="submit" class="button"><?php esc_html_e('Créer la page du calendrier', 'community-race-calendar'); ?></button>
                </form>
            </section>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderGeoapifyNotice(string $status): void
    {
        $notices = [
            'saved' => ['success', __('La clé Geoapify est enregistrée et la connexion fonctionne.', 'community-race-calendar')],
            'saved_unverified' => ['warning', __('La clé est enregistrée, mais Geoapify n’a pas pu être joint. Relancez le test dans quelques instants.', 'community-race-calendar')],
            'removed' => ['success', __('La clé enregistrée dans WordPress a été supprimée.', 'community-race-calendar')],
            'invalid' => ['error', __('Le format de la clé est invalide. Copiez la clé complète depuis votre espace Geoapify.', 'community-race-calendar')],
            'rejected' => ['error', __('Geoapify a refusé cette clé. Elle n’a pas été enregistrée.', 'community-race-calendar')],
            'missing' => ['error', __('Ajoutez d’abord une clé Geoapify.', 'community-race-calendar')],
            'test_success' => ['success', __('Connexion Geoapify validée. La recherche de lieux est opérationnelle.', 'community-race-calendar')],
            'test_failed' => ['error', __('Le test Geoapify a échoué. Remplacez la clé ou réessayez dans quelques instants.', 'community-race-calendar')],
        ];
        if (!isset($notices[$status])) {
            return;
        }
        [$type, $message] = $notices[$status];
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }
}
