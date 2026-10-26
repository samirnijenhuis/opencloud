output "access_key_id" {
  value = digitalocean_spaces_key.this.access_key
}

output "secret_key" {
  value     = digitalocean_spaces_key.this.secret_key
  sensitive = true
}
