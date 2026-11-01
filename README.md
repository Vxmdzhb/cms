# astrx/cms

The always-on content-management features of [AstrX](https://github.com/Vxmdzhb/astrx):
accounts (registration, login, two-factor, recovery, profiles), the admin panel
(users, grants, pages, navigation, themes, configuration, audit log, panic
lockdown), comments, news, invites and data retention.

![The admin panel](docs/screenshot.png)

It also defines the extension points optional modules answer
(`Retention\RetentionSource`, `Page\SitemapSource`, `User\LoginListener`,
`User\UserHomeSection`, `Admin\AdminDashboardSection`; see
[modules](https://github.com/Vxmdzhb/astrx/blob/main/docs/MODULES.md)).

`resources/` holds the admin and account templates, their catalogs (en, it),
the diagnostic messages and the default configuration. A site overrides any of
them from its own `resources/`.

Requires `astrx/api`, `astrx/auth`, `astrx/captcha`, `astrx/config`, `astrx/csrf`, `astrx/error-handler`, `astrx/http`, `astrx/i18n`, `astrx/identicon`, `astrx/image`, `astrx/kernel`, `astrx/mail`, `astrx/module`, `astrx/pagination`, `astrx/result`, `astrx/routing`, `astrx/session`, `astrx/support` and `astrx/template`.

AstrX isn't on Packagist and doesn't tag versions: you install from each
repository's `main`, and your `composer.lock` pins the exact commit. Tell
Composer where to find the package and the AstrX packages it depends on:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/cms" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/result" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/support" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/config" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/auth" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/api" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/http" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/i18n" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/session" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/kernel" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/routing" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/template" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/captcha" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/csrf" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/error-handler" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/identicon" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/image" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/mail" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/injector" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/module" },
        { "type": "vcs", "url": "https://github.com/Vxmdzhb/pagination" }
    ]
}
```

Then:

```sh
composer require astrx/cms:dev-main
```

Issues and pull requests are welcome here. [AstrX](https://github.com/Vxmdzhb/astrx)
is the site that puts all the packages together.

## License

Released under the [Do Whatever License](LICENSE), with a code of conduct
in the same spirit: [CODE_OF_CONDUCT](CODE_OF_CONDUCT).
