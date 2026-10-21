// docker-bake.hcl
variable "REGISTRY" { default = "" }
variable "VERSION" { default = "latest" }
variable "CACHE" { default = "" }

group "default" {
  targets = ["app"]
}

target "app" {
  context    = "./"
  dockerfile = "Dockerfile.verdaccio"
  platforms = [
    "linux/amd64",
  ]
  target = "aws-app"

  tags = notequal("", REGISTRY) ? formatlist(
    "${REGISTRY}:%s",
    compact(["latest", VERSION])
  ) : []

  cache-from = notequal("", CACHE) ? ["${CACHE}"] : []
  cache-to   = notequal("", CACHE) ? ["${CACHE},mode=max,ignore-error=true"] : []
}
