# Fly-Laravel

Fly-Laravel was created by Fly.io and is a quick way to get a Laravel app running on Fly.io. It was built using [Laravel Zero](https://laravel-zero.com).

## Usage

Fly-Laravel assumes that you have flyctl installed, and that you have it connected to your [Fly.io](https://www.fly.io) account. If you need help with this, check out  https://fly.io/docs/speedrun/. 

To get an app running on Fly.io, run `./vendor/bin/fly-laravel launch`. This will create (among other things) a `fly.toml` file which holds the entire app configuration. For more info about fly.toml app configuration, check out [the docs](https://fly.io/docs/reference/configuration/).

For ease of use, you could configure a shell alias, like this: `alias fly-laravel='php vendor/bin/fly-laravel'`.
To make this available everywhere, you can add this to your shell configuration file in your home directory, like `~/.zshrc` or `~/.bashrc`. Don't forget to restart your shell after. 

## License

Fly-Laravel is an open-source software licensed under the MIT license.
