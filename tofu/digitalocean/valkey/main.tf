# A DigitalOcean managed Valkey with one node. It accepts TLS connections only, and only the Kubernetes cluster can
# connect. The provider reads the API token from DIGITALOCEAN_TOKEN.

terraform {
  required_version = ">= 1.13.0"

  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "2.106.0"
    }
  }
}

resource "digitalocean_database_cluster" "this" {
  name                 = var.name
  engine               = "valkey"
  version              = var.engine_version
  size                 = var.size
  region               = var.region
  node_count           = 1
  private_network_uuid = var.vpc_id
}

resource "digitalocean_database_firewall" "this" {
  cluster_id = digitalocean_database_cluster.this.id

  rule {
    type  = "k8s"
    value = var.kubernetes_cluster_id
  }
}
