# A Spaces bucket and a key that can read and write that bucket only. The app gets that key.
# The provider reads the API token from DIGITALOCEAN_TOKEN for the key, and the full-access key of the
# spaces-admin-key stack from SPACES_ACCESS_KEY_ID and SPACES_SECRET_ACCESS_KEY for the bucket.

terraform {
  required_version = ">= 1.13.0"

  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "2.106.0"
    }
  }
}

# A detach destroys the bucket and its files, like it destroys a managed database and its data.
resource "digitalocean_spaces_bucket" "this" {
  name          = var.name
  region        = var.region
  acl           = "private"
  force_destroy = true
}

resource "digitalocean_spaces_key" "this" {
  name = var.name

  grant {
    bucket     = digitalocean_spaces_bucket.this.name
    permission = "readwrite"
  }
}
