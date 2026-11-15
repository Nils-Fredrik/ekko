# Certificate DNS authorization is independent of Cloud Run bootstrap. This lets
# the DNS owner publish the real records while OAuth/workloads are prepared.
locals {
  production_domain = "ekko.maksimer.com"
  https_backend     = var.enable_https && var.enable_workloads
}

resource "google_compute_global_address" "web" {
  lifecycle { prevent_destroy = true }
  count      = var.enable_https ? 1 : 0
  name       = "ekko-web"
  ip_version = "IPV4"
  depends_on = [google_project_service.api]
}

resource "google_certificate_manager_dns_authorization" "web" {
  lifecycle { prevent_destroy = true }
  count      = var.enable_https ? 1 : 0
  name       = "ekko-web"
  domain     = local.production_domain
  type       = "PER_PROJECT_RECORD"
  depends_on = [google_project_service.api]
}

resource "google_certificate_manager_certificate" "web" {
  lifecycle { prevent_destroy = true }
  count = var.enable_https ? 1 : 0
  name  = "ekko-web"
  managed {
    domains            = [local.production_domain]
    dns_authorizations = [google_certificate_manager_dns_authorization.web[0].id]
  }
}

resource "google_certificate_manager_certificate_map" "web" {
  lifecycle { prevent_destroy = true }
  count      = var.enable_https ? 1 : 0
  name       = "ekko-web"
  depends_on = [google_project_service.api]
}

resource "google_certificate_manager_certificate_map_entry" "web" {
  lifecycle { prevent_destroy = true }
  count        = var.enable_https ? 1 : 0
  name         = "ekko-web"
  map          = google_certificate_manager_certificate_map.web[0].name
  certificates = [google_certificate_manager_certificate.web[0].id]
  hostname     = local.production_domain
}

resource "google_compute_region_network_endpoint_group" "web" {
  count                 = local.https_backend ? 1 : 0
  name                  = "ekko-web"
  region                = var.region
  network_endpoint_type = "SERVERLESS"
  cloud_run { service = google_cloud_run_v2_service.web[0].name }
}

resource "google_compute_backend_service" "web" {
  count                 = local.https_backend ? 1 : 0
  name                  = "ekko-web"
  load_balancing_scheme = "EXTERNAL_MANAGED"
  protocol              = "HTTP"
  enable_cdn            = false
  backend { group = google_compute_region_network_endpoint_group.web[0].id }
}

resource "google_compute_url_map" "web" {
  count           = local.https_backend ? 1 : 0
  name            = "ekko-web"
  default_service = google_compute_backend_service.web[0].id
  lifecycle {
    precondition {
      condition     = var.app_url == "https://${local.production_domain}"
      error_message = "The production load balancer requires https://ekko.maksimer.com as app_url."
    }
  }
}

resource "google_compute_ssl_policy" "web" {
  count           = local.https_backend ? 1 : 0
  name            = "ekko-web"
  profile         = "MODERN"
  min_tls_version = "TLS_1_2"
}

resource "google_compute_target_https_proxy" "web" {
  count           = local.https_backend ? 1 : 0
  name            = "ekko-web"
  url_map         = google_compute_url_map.web[0].id
  ssl_policy      = google_compute_ssl_policy.web[0].id
  certificate_map = "//certificatemanager.googleapis.com/${google_certificate_manager_certificate_map.web[0].id}"
}

resource "google_compute_global_forwarding_rule" "https" {
  count                 = local.https_backend ? 1 : 0
  name                  = "ekko-web-https"
  target                = google_compute_target_https_proxy.web[0].id
  ip_address            = google_compute_global_address.web[0].address
  port_range            = "443"
  load_balancing_scheme = "EXTERNAL_MANAGED"
}

resource "google_compute_url_map" "redirect" {
  count = local.https_backend ? 1 : 0
  name  = "ekko-web-https-redirect"
  default_url_redirect {
    host_redirect          = local.production_domain
    https_redirect         = true
    redirect_response_code = "MOVED_PERMANENTLY_DEFAULT"
    strip_query            = false
  }
}

resource "google_compute_target_http_proxy" "redirect" {
  count   = local.https_backend ? 1 : 0
  name    = "ekko-web-https-redirect"
  url_map = google_compute_url_map.redirect[0].id
}

resource "google_compute_global_forwarding_rule" "http" {
  count                 = local.https_backend ? 1 : 0
  name                  = "ekko-web-http"
  target                = google_compute_target_http_proxy.redirect[0].id
  ip_address            = google_compute_global_address.web[0].address
  port_range            = "80"
  load_balancing_scheme = "EXTERNAL_MANAGED"
}

output "production_dns_records" {
  description = "Publish at the existing DNS provider. No Cloud DNS zone or nameserver change is needed."
  value = var.enable_https ? [
    { name = "${local.production_domain}.", type = "A", data = google_compute_global_address.web[0].address, ttl = 300 },
    { name = google_certificate_manager_dns_authorization.web[0].dns_resource_record[0].name,
      type = google_certificate_manager_dns_authorization.web[0].dns_resource_record[0].type,
    data = google_certificate_manager_dns_authorization.web[0].dns_resource_record[0].data, ttl = 300 },
  ] : []
}

output "google_oauth_redirect_uri" {
  value = "${var.app_url}/auth/google/callback"
}
