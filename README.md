# Fleetbase Extensions Registry

## Introduction

Welcome to the official source code repository for the Fleetbase Extensions Registry. This platform serves as a comprehensive package registry, catering to both Composer and npm packages, specifically tailored for Fleetbase extensions. Our unique focus on "Fleetbase Extensions" ensures a seamless integration and enhanced functionality for Fleetbase users.

## Features

- **Dual Package Support**: Compatible with Composer and npm packages, offering a versatile solution for different development needs.
- **Fleetbase Extensions Specialization**: Dedicated support for Fleetbase-specific extensions, enhancing the Fleetbase ecosystem.
- **Extension Validation**: Each Fleetbase extension is required to include an `extension.json` file, ensuring standardization and quality control.

## Getting Started

### Prerequisites

- Docker and Docker Compose
- Basic understanding of Composer and npm package management

### Installation

1. **Clone the Repository**: 

    ```
    git clone git@github.com:fleetbase/registry.git
    ```

2. **Set Up the Environment**:

    ```
    sh install-plugins.sh
    ```

3. **Build and Run with Docker Compose**:

    ```
    docker-compose up --build
    ```

### Configuration

All credentials come from the environment; nothing secret is committed.

1. Copy `docker-compose.override.yml.example` to `docker-compose.override.yml` (git-ignored) and `.env.example` to `.env` (git-ignored), then fill in the values in `.env`:
   - `AWS_BUCKET`, `AWS_REGION`, `AWS_ENDPOINT`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` for the tarball bucket (MinIO locally, S3 in production).
   - `FLEETBASE_HOST` and `FLEETBASE_API_KEY` for the Fleetbase API the auth and composer plugins call.
   - `DEV_REWRITE_MINIO_HOST=1` only for local compose, so signed tarball URLs point at `localhost` instead of the `minio` service name.
2. In AWS the same variables are injected by `ssm-parent` from the SSM parameter named in `SSM_PARAMETER` (see `Dockerfile.verdaccio`).

`config/config.yaml` holds no secrets. Every push and pull request runs a gitleaks history scan (`.github/workflows/secret-scan.yml`).

## Usage

### Publishing a Package

```bash
npm i -g flb-cli
flb-cli publish [my-extension]
```

### Installing an Extension

Update composer.json

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://registry.fleetbase.io"
        }
    ]
}
```

## Contributing

We welcome contributions to the Fleetbase Extensions Registry! Please read our [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to make a contribution.

## Support and Contact

For support or inquiries, please [open an issue](link-to-issue-tracker).

## License

This project is licensed under the APGLV v3 License - see the [LICENSE.md](LICENSE.md) file for details.
