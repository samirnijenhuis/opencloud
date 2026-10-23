variable "name" {
  type        = string
  description = "The cluster name on DigitalOcean."
}

variable "region" {
  type        = string
  description = "The region slug, for example ams3."
}

variable "node_size" {
  type        = string
  description = "The Droplet size slug of the nodes, for example s-2vcpu-4gb."
}

variable "min_nodes" {
  type        = number
  description = "The autoscaler keeps at least this many nodes."

  validation {
    condition     = var.min_nodes >= 1
    error_message = "The node pool needs at least one node."
  }
}

variable "max_nodes" {
  type        = number
  description = "The autoscaler adds nodes up to this number."

  validation {
    condition     = var.max_nodes >= var.min_nodes
    error_message = "max_nodes must be at least min_nodes."
  }
}

variable "registry_name" {
  type        = string
  description = "The name of the registry of the account. Registry names are unique across DigitalOcean."
}

variable "create_registry" {
  type        = bool
  description = "True when the account has no registry yet."
}

variable "registry_tier" {
  type        = string
  default     = "basic"
  description = "The tier of a new registry. Opencloud uses one repository per environment, and the starter tier allows one."
}
