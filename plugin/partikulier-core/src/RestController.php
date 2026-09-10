<?php

declare(strict_types=1);

namespace Partikulier\Core;

use Partikulier\Core\Rest\RouteRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestController
{
    private ?ListingRepository $repository = null;
    private ?ListingPolicy $policy = null;
    private ?SearchService $search = null;
    private ?ListingService $service = null;
    private ?AuditLogger $audit = null;
    private ?LeadService $leads = null;
    private ?FavoriteService $favorites = null;
    private ?RateLimiter $rateLimiter = null;
    private ?HealthCheck $health = null;

    public function __construct()
    {
        // Les services sont construits à la demande : les lectures publiques ne
        // initialisent ni leads, ni favoris, ni santé, ni écriture.
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    private function repository(): ListingRepository
    {
        return $this->repository ??= new ListingRepository();
    }

    private function policy(): ListingPolicy
    {
        return $this->policy ??= new ListingPolicy();
    }

    private function search(): SearchService
    {
        return $this->search ??= new SearchService($this->repository());
    }

    private function service(): ListingService
    {
        return $this->service ??= new ListingService($this->repository(), $this->audit());
    }

    private function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger();
    }

    private function leads(): LeadService
    {
        return $this->leads ??= new LeadService();
    }

    private function favorites(): FavoriteService
    {
        return $this->favorites ??= new FavoriteService();
    }

    private function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter ??= new RateLimiter();
    }

    private function health(): HealthCheck
    {
        return $this->health ??= new HealthCheck();
    }

    /**
     * Toutes les déclarations passent par le registre unique (INTEG-3) :
     * l'espace de noms partikulier/v1 n'est écrit qu'une fois, dans
     * RouteRegistry, et toute collision est refusée et journalisée.
     */
    public function registerRoutes(): void
    {
        RouteRegistry::declare('/listings', [
            'methods' => 'GET',
            'callback' => [$this, 'listings'],
            'permission_callback' => [$this, 'guardPublic'],
            'args' => $this->listArgs(),
        ], 'plugin');

        RouteRegistry::declare('/listings/(?P<id>[0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'listing'],
            'permission_callback' => [$this, 'guardPublic'],
            'args' => ['id' => ['required' => true, 'validate_callback' => static fn($value): bool => ctype_digit((string) $value)]],
        ], 'plugin');

        RouteRegistry::declare('/listings', [
            'methods' => 'POST',
            'callback' => [$this, 'createListing'],
            'permission_callback' => [$this, 'guardWrite'],
            'args' => [
                'title' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                'description' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                'locale' => ['required' => false, 'default' => 'fr', 'type' => 'string'],
                'price' => ['required' => true, 'type' => 'number', 'minimum' => 0],
                'area' => ['required' => true, 'type' => 'number', 'minimum' => 0.01],
            ],
        ], 'plugin');

        // INTEG-2 : mêmes champs que le parcours du site — coordonnée
        // téléphone, rattachement obligatoire à l'annonce visée.
        RouteRegistry::declare('/leads', [
            'methods' => 'POST',
            'callback' => [$this, 'createLead'],
            'permission_callback' => [$this, 'guardLead'],
            'args' => [
                'phone' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'property_id' => ['required' => false, 'type' => 'integer', 'minimum' => 1],
                'reference' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'message' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                'name' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'email' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            ],
        ], 'plugin');

        // Lot B2 — rétention du domaine leads : effacement explicite d'un lead
        // au canal WhatsApp/n8n. Route reprise du thème (owner theme → plugin,
        // même espace de noms, mêmes arguments — port fidèle : pas de
        // permission_callback, l'authentification n8n est assurée en amont par
        // l'orchestrateur, comme sur les quatre routes de qualification).
        RouteRegistry::declare('/erase-lead', [
            'methods' => 'POST',
            'callback' => static fn(\WP_REST_Request $request) => \Partikulier\Core\Domain\Leads\LeadService::rest_erase_request($request),
        ], 'plugin');

        // Lot B4 — pont d'automatisation entrant : accusé d'événement
        // normalisé n8n. Route reprise du thème (owner theme → plugin, même
        // espace de noms, mêmes arguments — port fidèle : la garde est
        // check_automation_secret, exactement comme
        // Partikulier_Automation_Bridge::register_route le faisait).
        RouteRegistry::declare('/automation-event', [
            'methods' => 'POST',
            'callback' => static fn(\WP_REST_Request $request) => \Partikulier\Core\Domain\Automation\AutomationService::rest_receive_event($request),
            'permission_callback' => static fn(\WP_REST_Request $request) => \Partikulier\Core\Domain\Automation\AutomationService::check_automation_secret($request),
        ], 'plugin');

        RouteRegistry::declare('/favorites', [
            'methods' => 'POST',
            'callback' => [$this, 'toggleFavorite'],
            'permission_callback' => [$this, 'guardPrivate'],
            'args' => ['listing_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
        ], 'plugin');

        RouteRegistry::declare('/health', [
            'methods' => 'GET',
            'callback' => fn(): WP_REST_Response => new WP_REST_Response($this->health()->get(), 200),
            'permission_callback' => [$this, 'guardPublic'],
        ], 'plugin');
    }

    public function guardPublic(WP_REST_Request $request): bool|WP_Error
    {
        return $this->rateLimiter()->guard($request, 'public', $this->policy()->canReadPublic(), 180, 60);
    }

    public function guardWrite(WP_REST_Request $request): bool|WP_Error
    {
        return $this->rateLimiter()->guard($request, 'write', $this->policy()->canCreate(), 30, 60);
    }

    public function guardLead(WP_REST_Request $request): bool|WP_Error
    {
        return $this->rateLimiter()->guard($request, 'lead', $this->policy()->canReadPublic(), 10, 60);
    }

    public function guardPrivate(WP_REST_Request $request): bool|WP_Error
    {
        return $this->rateLimiter()->guard($request, 'private', $this->policy()->canReadPrivate(), 60, 60);
    }

    private function listArgs(): array
    {
        return [
            'locale' => ['required' => false, 'default' => 'fr', 'type' => 'string'],
            'order' => ['required' => false, 'default' => 'newest', 'enum' => SearchService::ALLOWED_ORDERS, 'type' => 'string'],
            'page' => ['required' => false, 'default' => 1, 'type' => 'integer', 'minimum' => 1],
            'per_page' => ['required' => false, 'default' => 24, 'type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        ];
    }

    public function listings(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['data' => $this->search()->search($request->get_params()), 'page' => (int) $request['page']], 200);
    }

    public function listing(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->repository()->find((int) $request['id']);
        return is_wp_error($result) ? $result : new WP_REST_Response(['data' => $result], 200);
    }

    public function createListing(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = $this->service()->create($request->get_json_params() ?: $request->get_params(), get_current_user_id());
        return is_wp_error($id) ? $id : new WP_REST_Response(['id' => $id, 'status' => 'draft'], 201);
    }

    public function createLead(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->leads()->create($request->get_json_params() ?: $request->get_params());
        return is_wp_error($result) ? $result : new WP_REST_Response(['data' => $result], 201);
    }

    public function toggleFavorite(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->favorites()->toggle((int) $request['listing_id'], get_current_user_id());
        return is_wp_error($result) ? $result : new WP_REST_Response(['data' => $result], 200);
    }
}
