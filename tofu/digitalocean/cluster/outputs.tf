output "cluster_id" {
  value = digitalocean_kubernetes_cluster.this.id
}

output "vpc_id" {
  value = digitalocean_kubernetes_cluster.this.vpc_uuid
}

output "kubeconfig" {
  value     = local.kubeconfig
  sensitive = true
}

output "registry_endpoint" {
  value = data.digitalocean_container_registry.this.endpoint
}

output "registry_username" {
  value     = local.registry_auth[0]
  sensitive = true
}

output "registry_password" {
  value     = join(":", slice(local.registry_auth, 1, length(local.registry_auth)))
  sensitive = true
}
