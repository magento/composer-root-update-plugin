# Contributing to Magento code

Contributions to the Magento codebase are done using the fork & pull model.
This contribution model has contributors maintaining their own copy of the forked codebase (which can easily be synced with the main copy). The forked repository is then used to submit a request to the base repository to “pull” a set of changes (hence the phrase “pull request”).

Contributions can take the form of new components/features, changes to existing features, tests, documentation (such as developer guides, user guides, examples, or specifications), bug fixes, optimizations, or just good suggestions.

The Magento development team will review all issues and contributions submitted by the community of developers in first-in, first-out order. During the review we might require clarifications from the contributor. If there is no response from the contributor for two weeks, the issue is closed.

For large features or changes, please [open an issue](https://github.com/magento/composer-root-update-plugin/issues) for discussion before submitting any code. This will prevent duplicate or unnecessary effort and can also increase the number of people involved in discussing and implementing the change.

## Contribution requirements

1. Contributions must adhere to [Magento coding standards](http://devdocs.magento.com/guides/v2.3/coding-standards/bk-coding-standards.html).
2. Pull requests (PRs) must be accompanied by a complete and meaningful description. Comprehensive descriptions make it easier to understand the reasoning behind a change and reduce the amount of time required to get the PR merged.
3. Commits must be accompanied by meaningful commit messages.
4. PRs which include bug fixing must be accompanied by step-by-step instructions how to reproduce the issue (including the local composer version reported by `composer --version`).
5. PRs which include new logic or new features must be submitted along with:
    * Unit/integration test coverage where applicable.
    * Updated documentation in the project's `docs` directory.
6. All automated tests must pass successfully.

Any contributions that do not meet these requirements will not be accepted.

### Composer compatibility

Maintaining compatibility with the Composer versions listed in the [composer.json](composer.json) file is important for this project. Due to the way Composer works with plugins, the version that is used when the plugin runs is the local `composer.phar` executable version (as reported by `composer --version`) and not the version installed in the project's `vendor` folder or `composer.lock` file This means that in order to properly verify Composer compatibility, tests must be run against the local `composer.phar` executable, not just the installed `composer/composer` dependency.

Additionally, because of the way the plugin interacts with the native `composer require` command, some parts of the Composer library sometimes need to be re-implemented in an accessible manner if the original code is in private methods or part of larger functions. Such implementations should be located in the [Magento\ComposerRootUpdatePlugin\ComposerReimplementation](src/Magento/ComposerRootUpdatePlugin/ComposerReimplementation) namespace and documented with the reason for re-implementation and a link to the original method. 

## Contribution process

If you are a new GitHub user, we recommend that you create your own [free GitHub account](https://github.com/signup/free). By doing so, you will be able to collaborate with the Magento development team, fork the GitHub project and easily send pull requests for any changes you wish to contribute.

1. Search the current listed issues (open or closed) on the [magento/composer-root-update-plugin](https://github.com/magento/composer-root-update-plugin/issues) and [magento/magento2](https://github.com/magento/magento2/issues) GitHub repositories before starting work on a new contribution.
2. Review the [Contributor License Agreement](https://magento.com/legaldocuments/mca) if this is your first time contributing.
3. Create and test your work.
4. Fork the repository according to the [Fork a repository instructions](http://devdocs.magento.com/guides/v2.3/contributor-guide/contributing.html#fork).
5. When you are ready to send us a pull request, follow the [Create a pull request instructions](http://devdocs.magento.com/guides/v2.3/contributor-guide/contributing.html#pull_request). The instructions are written for the `https://github.com/magento/magento2` repository, but they also apply to `https://github.com/magento/composer-root-update-plugin`.
6. Once your contribution is received, the Magento 2 development team will review the contribution and collaborate with you as needed if it is accepted.

## Code of Conduct

Please note that this project is released with a Contributor Code of Conduct. We expect you to agree to its terms when participating in this project.
The full text is available in the Magento 2 repository [Wiki](https://github.com/magento/magento2/wiki/Magento-Code-of-Conduct).