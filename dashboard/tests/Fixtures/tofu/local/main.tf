variable "message" {
  type = string
}

resource "terraform_data" "this" {
  input = var.message
}

output "id" {
  value = terraform_data.this.id
}

output "message" {
  value     = terraform_data.this.output
  sensitive = true
}
