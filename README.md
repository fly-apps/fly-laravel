# Fly-Laravel

Fly-Laravel was created by Fly.io and is a quick way to get a Laravel app running on Fly.io. It was built using [Laravel Zero](https://laravel-zero.com).

## Disclaimer

Fly-Laravel assumes that you have flyctl installed, and that you have it connected to your [Fly.io](https://www.fly.io) account. If you need help with this, check out  https://fly.io/docs/speedrun/.

These commands will help you set up apps on Fly.io. **Remember that running these apps can cost money!** 

You can find more about Fly.io's free allowance and pricing [here](https://fly.io/docs/about/pricing/).

## Installation 

Run `composer require fly-apps/fly-laravel` to install the latest version. 

By default, commands are invoked using the `vendor/bin/fly-laravel` script. To avoid have to type all that for ecery command, you may configure a shell alias: 

```shell
alias fly-laravel='vendor/bin/fly-laravel'
```

To make sure this is always available, you may add this to your shell configuration file in your home directory, such as `~/.zshrc` or `~/.bashrc`, and then restart your shell.

## Usage 

With this package, you can spin up Laravel, MySQL and/or Redis apps on Fly.io . There are two commands for every type of app: `launch` and `deploy`.

- `Launch` will create a new application on Fly.io in the organization you choose.
- `Deploy` will (re)deploy the app. This will update the app you've already created with `launch`.

### Prerequisites
- You have an account on Fly.io 
- You have created an organization on Fly.io
- You have installed the flyctl agent.

### Laravel

#### Launch

Run `fly-laravel launch` to create a new Laravel application. You will be able to pick the name, what organization to deploy in and what extra services you want to set up.
- **App name**: What the app on Fly.io will be called. This can only contain alphanumeric characters and hyphens, for DNS reasons.
- **Primary Region**: The primary region to deploy your app in. You should pick the region closest to your users. You can always add more regions, as specified in the [Scaling Documentation](https://fly.io/docs/apps/scale-count/#add-a-new-region)
- **Organization**: On Fly.io, apps can be grouped into organizations. Organizations are a great way to divide up apps, share access with team members and manage billing. If there's only one organization available we'll auto-select that one.
- **Services**: you can pick if you want to run [cron](https://laravel.com/docs/10.x/scheduling) or a [queue worker](https://laravel.com/docs/10.x/queues#main-content) in the app. This will create a [process group](https://fly.io/docs/apps/processes/) for each extra service, to scale independently. 

To set up the app, a number of steps will occur to set up a basic Laravel app: 
- The locally installed Node and PHP versions are detected
- The fly.toml app configuration file is generated. If you want to make changes to your app later on, this is where to do it. 
- Some folders and files are copied over, most notably the Dockerfile.
- A randomly generated `APP_KEY` will be set as a [secret](https://fly.io/docs/reference/secrets/) on your app. 

A note on the configured `SESSION_DRIVER` in the `fly.toml` file:
- By default, your Laravel app will be configured with [Cookie-based session storage](https://fly.io/laravel-bytes/taking-laravel-global/#:~:text=The-,simplest%20solution,-here%20is%20to). This allows sessions to work across multiple instances of your web app without the need of an external session service like Redis to make session data available to all the instances. Of course Cookie-based session storage has limits on how much session data it can store, so you might want to consider replacing this to allow storage of larger data.

After set up, your app will be ready to deploy! In your project root, a `.fly` folder will be added alongside a `Dockerfile` and a `fly.toml` file. 

When launching databases you will need to deploy again so launch those before deploying the laravel app. 

#### Deploy

Run `fly-laravel deploy` to deploy your Laravel app. This will update the running app (if any) to include your latest changes. Add the `--open` flag to open the app in your browser when it has been deployed. 

### MySQL

#### Launch

Run `fly-laravel launch:mysql` to create a new MySQL application. You will be able to pick the app name, what organization to deploy in, the MySQL username and the volume name. If a Laravel app is detected, you can opt to use the same organization and primary region.
- **App Name**: What the app on Fly.io will be called. This can only contain alphanumeric characters and hyphens, for DNS reasons. By default, `[laravel app name]-db` will be proposed as the app name. 
- **Organization**: On Fly.io, apps can be grouped into organizations. Organizations are a great way to divide up apps, share access with team members and manage billing. If there's only one organization available we'll auto-select that one.
- **Primary Region**: The primary region to deploy your app in. You should pick the region closest to your users. You can always add more regions, as specified in the [Scaling Documentation](https://fly.io/docs/apps/scale-count/#add-a-new-region) 
- **Volume Name**: For data persistence, a volume will be needed for database applications. If there's a volume with this name available, we'll use that. If no volume with this name can be found, a 1GB volume will be created on deploy. More about volumes here: [Volume Documentation](https://fly.io/docs/reference/volumes/). 

Some notes when launching a MySQL database: 
- During the launch, some environment variables will be updated in the `fly.toml` configuration of the Laravel app. Redeploying the Laravel app will be necessary to reflect these changes.
- The `DB_CONNECTION` env var in `fly.toml` will be set to 'mysql'
- On deploy, a small scale machine will be provisioned with a 1x shared CPU and 256Mb of memory. Consider scaling up the database for better performance.
- By default, the `innodb buffer pool size` will be set to 64MB. Consider optimizing this based on your performance requirements. You can find this in `.fly/mysql/fly.toml`, in the `[processes]` section.
- For the networking to work properly, the Laravel app and MySQL app should be in the same organization.

#### Deploy

Run `fly-laravel deploy:mysql` to deploy the MySQL application. After the deployment we'll run a quick check of the machine resources, and show a warning if the memory is smaller than 1GB.

### Redis

#### Launch

Run `fly-laravel launch:redis` to launch a Redis application. You will be able to pick the app name, what organization to deploy in and the volume name. If a Laravel app is detected, you can opt to use the same organization and primary region.
- **App Name**: What the app on Fly.io will be called. This can only contain alphanumeric characters and hyphens, for DNS reasons. By default, `[laravel app name]-db` will be proposed as the app name.
- **Organization**: On Fly.io, apps can be grouped into organizations. Organizations are a great way to divide up apps, share access with team members and manage billing. If there's only one organization available we'll auto-select that one.
- **Primary Region**: The primary region to deploy your app in. You should pick the region closest to your users. You can always add more regions, as specified in the [Scaling Documentation](https://fly.io/docs/apps/scale-count/#add-a-new-region)
- **Volume Name**: For data persistence, a volume will be needed for database applications. If there's a volume with this name available, we'll use that. If no volume with this name can be found, a 1GB volume will be created on deploy. More about volumes here: [Volume Documentation](https://fly.io/docs/reference/volumes/).

Some notes when launching a Redis application:
- During the launch, some Laravel environment variables will be updated in its `fly.toml` configuration. Redeploying the Laravel app will be necessary to reflect these changes.
- The `CACHE_DRIVER` and `SESSION_DRIVER` env vars in `fly.toml` will be set to 'redis'
- On deploy, a small scale machine will be provisioned with a 1x shared CPU and 256Mb of memory. Consider scaling up the database for better performance.
- For the networking to work properly, the Laravel app and Redis app should be in the same organization.

#### Deploy

Run `fly-laravel deploy:redis` to deploy the Redis application. After the deployment we'll run a quick check of the machine resources, and show a warning if the memory is smaller than 1GB.

### Volume

#### Mount

Run `fly-laravel mount:volume` to mount Volume to your Laravel Fly app's storage directory and persist the files saved here! The command will create the necessary number of Volume(s) in the proper regions, matching the number of machines per region in your Fly app. It will then update your `fly.toml` file's mount section to use the Volume(s)' name, and, finally create a script necessary to re-initialize the storage folder( as initially mounting a volume to the folder will erase its content ).

After set up, your app will be ready to deploy with changes to mount the created Volume(s)! This is why there is a last prompt from the command asking whether to deploy the changes or not. You can confirm--this will deploy your changes, or decline--your application will be ready to mount the Volume(s) when you deploy manually.

## Further Reading
For more information about fly.io, check out the [Fly.io Docs](https://fly.io/docs/).

For more Laravel-related content, check out the [Laravel-Bytes blog](https://fly.io/laravel-bytes/).

## License

Fly-Laravel is an open-source software licensed under the MIT license.
