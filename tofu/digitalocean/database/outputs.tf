output "host" {
  value = digitalocean_database_cluster.this.private_host
}

output "port" {
  value = digitalocean_database_cluster.this.port
}

output "database" {
  value = digitalocean_database_db.this.name
}

output "user" {
  value = digitalocean_database_user.this.name
}

output "password" {
  value     = digitalocean_database_user.this.password
  sensitive = true
}
