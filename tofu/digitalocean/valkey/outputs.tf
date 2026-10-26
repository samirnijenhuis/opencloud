output "host" {
  value = digitalocean_database_cluster.this.private_host
}

output "port" {
  value = digitalocean_database_cluster.this.port
}

output "password" {
  value     = digitalocean_database_cluster.this.password
  sensitive = true
}
