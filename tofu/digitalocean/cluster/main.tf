# A DigitalOcean Kubernetes cluster with one autoscaling node pool, and the container registry of the account.
# The provider reads the API token from DIGITALOCEAN_TOKEN.

terraform {
  required_version = ">= 1.13.0"

  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "2.106.0"
    }
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "3.3.0"
    }
  }
}

data "digitalocean_kubernetes_versions" "this" {}

resource "digitalocean_kubernetes_cluster" "this" {
  name         = var.name
  region       = var.region
  version      = data.digitalocean_kubernetes_versions.this.latest_version
  auto_upgrade = true

  node_pool {
    name       = "${var.name}-default"
    size       = var.node_size
    auto_scale = true
    min_nodes  = var.min_nodes
    max_nodes  = var.max_nodes
  }

  # auto_upgrade moves the cluster to new patch versions. A later apply must not undo that or start a minor upgrade.
  lifecycle {
    ignore_changes = [version]
  }
}

# DigitalOcean allows one registry per account. Destroy leaves it, because other clusters and their images can use it.
resource "digitalocean_container_registry" "this" {
  count = var.create_registry ? 1 : 0

  name                   = var.registry_name
  subscription_tier_slug = var.registry_tier

  lifecycle {
    destroy = false
  }
}

data "digitalocean_container_registry" "this" {
  name       = var.registry_name
  depends_on = [digitalocean_container_registry.this]
}

# A credential for the registry only. The pull secrets on the cluster hold it, so they cannot change the account.
resource "digitalocean_container_registry_docker_credentials" "this" {
  registry_name = data.digitalocean_container_registry.this.name
  write         = true
}

# The kubeconfig of DigitalOcean expires after seven days. A service account token does not expire.
provider "kubernetes" {
  host                   = digitalocean_kubernetes_cluster.this.endpoint
  token                  = digitalocean_kubernetes_cluster.this.kube_config[0].token
  cluster_ca_certificate = base64decode(digitalocean_kubernetes_cluster.this.kube_config[0].cluster_ca_certificate)
}

# These objects go with the cluster. Destroy skips them, so it does not need to reach the cluster API.
resource "kubernetes_service_account_v1" "opencloud" {
  metadata {
    name      = "opencloud"
    namespace = "kube-system"
  }

  lifecycle {
    destroy = false
  }
}

resource "kubernetes_cluster_role_binding_v1" "opencloud" {
  metadata {
    name = "opencloud"
  }

  role_ref {
    api_group = "rbac.authorization.k8s.io"
    kind      = "ClusterRole"
    name      = "cluster-admin"
  }

  subject {
    kind      = "ServiceAccount"
    name      = kubernetes_service_account_v1.opencloud.metadata[0].name
    namespace = "kube-system"
  }

  lifecycle {
    destroy = false
  }
}

resource "kubernetes_secret_v1" "opencloud" {
  metadata {
    name      = "opencloud-token"
    namespace = "kube-system"
    annotations = {
      "kubernetes.io/service-account.name" = kubernetes_service_account_v1.opencloud.metadata[0].name
    }
  }

  type                           = "kubernetes.io/service-account-token"
  wait_for_service_account_token = true

  lifecycle {
    destroy = false
  }
}

locals {
  registry_auth = split(":", base64decode(jsondecode(digitalocean_container_registry_docker_credentials.this.docker_credentials).auths[data.digitalocean_container_registry.this.server_url].auth))

  kubeconfig = yamlencode({
    apiVersion = "v1"
    kind       = "Config"
    clusters = [{
      name = var.name
      cluster = {
        server                     = digitalocean_kubernetes_cluster.this.endpoint
        certificate-authority-data = digitalocean_kubernetes_cluster.this.kube_config[0].cluster_ca_certificate
      }
    }]
    users = [{
      name = "opencloud"
      user = { token = kubernetes_secret_v1.opencloud.data["token"] }
    }]
    contexts = [{
      name    = var.name
      context = { cluster = var.name, user = "opencloud" }
    }]
    current-context = var.name
  })
}
