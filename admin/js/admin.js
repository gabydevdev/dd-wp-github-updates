/**
 * Admin JavaScript - Vanilla JS version
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // Test GitHub connection
        const testConnectionBtn = document.getElementById('test_github_connection');
        if (testConnectionBtn) {
            testConnectionBtn.addEventListener('click', function(e) {
                e.preventDefault();

                const statusEl = document.getElementById('connection_status');

                this.disabled = true;
                statusEl.innerHTML = '<span class="testing">' + dd_github_updates.testing_connection + '</span>';

                const formData = new FormData();
                formData.append('action', 'dd_github_test_connection');
                formData.append('nonce', dd_github_updates.nonce);
                formData.append('token', document.getElementById('github_token').value);
                formData.append('save', false);

                fetch(dd_github_updates.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        statusEl.innerHTML = '<span class="success">' + response.data + '</span>';
                    } else {
                        statusEl.innerHTML = '<span class="error">' + dd_github_updates.connection_error + response.data + '</span>';
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusEl.innerHTML = '<span class="error">' + dd_github_updates.connection_error + 'Server error occurred. Check your server logs.' + '</span>';
                })
                .finally(() => {
                    this.disabled = false;
                });
            });
        }

        // Check GitHub connection
        const checkGithubBtn = document.getElementById('check-github-connection');
        if (checkGithubBtn) {
            checkGithubBtn.addEventListener('click', function(e) {
                e.preventDefault();

                const statusEl = document.getElementById('github-connection-status');

                this.disabled = true;
                statusEl.innerHTML = '<span class="testing">' + dd_github_updates.testing_connection + '</span>';

                const formData = new FormData();
                formData.append('action', 'dd_github_test_connection');
                formData.append('nonce', dd_github_updates.nonce);
                formData.append('save', false);

                fetch(dd_github_updates.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        statusEl.innerHTML = '<span class="success">' + response.data + '</span>';
                    } else {
                        statusEl.innerHTML = '<span class="error">' + dd_github_updates.connection_error + response.data + '</span>';
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusEl.innerHTML = '<span class="error">' + dd_github_updates.connection_error + 'Server error occurred. Check your server logs and browser console for details.' + '</span>';
                })
                .finally(() => {
                    this.disabled = false;
                });
            });
        }

        // Toggle fields based on repository type
        const repoTypeSelect = document.getElementById('repo_type');
        if (repoTypeSelect) {
            repoTypeSelect.addEventListener('change', function() {
                const type = this.value;
                const themeFields = document.querySelectorAll('.theme-fields');
                const pluginFields = document.querySelectorAll('.plugin-fields');

                themeFields.forEach(field => field.style.display = 'none');
                pluginFields.forEach(field => field.style.display = 'none');

                if (type === 'theme') {
                    themeFields.forEach(field => field.style.display = 'block');
                } else if (type === 'plugin') {
                    pluginFields.forEach(field => field.style.display = 'block');
                }
            });
        }

        // Add repository form submission
        const addRepoForm = document.getElementById('dd-github-add-repo-form');
        if (addRepoForm) {
            addRepoForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const type = document.getElementById('repo_type').value;
                const owner = document.getElementById('repo_owner').value;
                const name = document.getElementById('repo_name').value;
                const slug = document.getElementById('theme_slug').value;
                const file = document.getElementById('plugin_file').value;

                // Basic validation
                if (!type || !owner || !name) {
                    alert('Please fill all required fields.');
                    return;
                }

                if (type === 'theme' && !slug) {
                    alert('Theme slug is required.');
                    return;
                }

                if (type === 'plugin' && !file) {
                    alert('Plugin file is required.');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'dd_github_add_repository');
                formData.append('nonce', dd_github_updates.nonce);
                formData.append('type', type);
                formData.append('owner', owner);
                formData.append('name', name);
                formData.append('slug', slug);
                formData.append('file', file);

                fetch(dd_github_updates.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        // Reload page to show updated repositories
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    alert('Server error occurred. Check your server logs.');
                });
            });
        }

        // Remove repository
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('remove-repo')) {
                if (!confirm(dd_github_updates.confirm_remove)) {
                    return;
                }

                const index = e.target.dataset.index;

                const formData = new FormData();
                formData.append('action', 'dd_github_remove_repository');
                formData.append('nonce', dd_github_updates.nonce);
                formData.append('index', index);

                fetch(dd_github_updates.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        // Reload page to show updated repositories
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    alert('Server error occurred. Check your server logs.');
                });
            }
        });

        // Search GitHub repository
        const searchForm = document.getElementById('dd-github-search-form');
        if (searchForm) {
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const owner = document.getElementById('repo_owner').value;
                const name = document.getElementById('repo_name').value;

                if (!owner || !name) {
                    alert('Please enter both owner and repository name.');
                    return;
                }

                const submitButton = this.querySelector('button[type="submit"]');
                const originalText = submitButton.textContent;

                submitButton.disabled = true;
                submitButton.textContent = dd_github_updates.searching;

                const formData = new FormData();
                formData.append('action', 'dd_github_search_repository');
                formData.append('nonce', dd_github_updates.nonce);
                formData.append('owner', owner);
                formData.append('name', name);

                fetch(dd_github_updates.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        // Display repository details
                        document.getElementById('repo-name').textContent = response.data.name;
                        document.getElementById('repo-description').textContent = response.data.description;
                        document.getElementById('repo-author').textContent = response.data.author;
                        document.getElementById('repo-version').textContent = response.data.version;
                        document.getElementById('repo-updated').textContent = response.data.updated_at;
                        document.getElementById('repo-license').textContent = response.data.license;

                        // Set values for the install form
                        document.getElementById('install_owner').value = owner;
                        document.getElementById('install_name').value = name;
                        document.getElementById('install_download_url').value = '';

                        // Format release notes with markdown if available
                        document.getElementById('repo-notes').innerHTML = response.data.release_notes;

                        // Show the repository details section
                        document.getElementById('dd-github-repo-details').style.display = 'block';
                    } else {
                        alert(dd_github_updates.search_error + response.data);
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    alert(dd_github_updates.search_error + 'Server error occurred. Check your server logs.');
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                });
            });
        }

        // Toggle install options based on type
        const installTypeSelect = document.getElementById('install_type');
        if (installTypeSelect) {
            installTypeSelect.addEventListener('change', function() {
                const typeOptions = document.querySelectorAll('.type-options');
                typeOptions.forEach(option => option.style.display = 'none');

                const type = this.value;
                if (type === 'theme') {
                    document.getElementById('theme-options').style.display = 'block';
                } else if (type === 'plugin') {
                    document.getElementById('plugin-options').style.display = 'block';
                }
            });
        }

        // Install from GitHub
        const installForm = document.getElementById('dd-github-install-form');
        if (installForm) {
            installForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const owner = document.getElementById('install_owner').value;
                const name = document.getElementById('install_name').value;
                const type = document.getElementById('install_type').value;
                const downloadUrl = document.getElementById('install_download_url').value;
                const addToUpdater = document.getElementById('add_to_updater').checked;

                let activate = false;
                let slug = '';

                if (type === 'theme') {
                    slug = document.getElementById('theme_slug').value;
                    activate = document.getElementById('activate_theme').checked;
                } else if (type === 'plugin') {
                    activate = document.getElementById('activate_plugin').checked;
                }

                if (!type || !owner || !name) {
                    alert('Missing required installation parameters.');
                    return;
                }

                const submitButton = this.querySelector('button[type="submit"]');
                const statusEl = document.getElementById('install-status');
                const originalText = submitButton.textContent;

                submitButton.disabled = true;
                submitButton.textContent = dd_github_updates.installing;
                statusEl.innerHTML = '<span class="installing">' + dd_github_updates.installing + '</span>';

                const formData = new FormData();
                formData.append('action', 'dd_github_install_from_github');
                formData.append('nonce', dd_github_updates.nonce);
                formData.append('type', type);
                formData.append('owner', owner);
                formData.append('name', name);
                formData.append('download_url', downloadUrl);
                formData.append('add_to_updater', addToUpdater);

                // Add type-specific parameters
                if (type === 'theme') {
                    formData.append('slug', slug);
                    formData.append('activate', activate);
                } else if (type === 'plugin') {
                    formData.append('activate', activate);
                }

                fetch(dd_github_updates.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())                .then(response => {
                    if (response.success) {
                        // Handle success response - extract message from data object
                        let message = '';
                        if (typeof response.data === 'object' && response.data.message) {
                            message = response.data.message;
                        } else if (typeof response.data === 'string') {
                            message = response.data;
                        } else {
                            message = dd_github_updates.install_success;
                        }

                        statusEl.innerHTML = '<span class="success">' + message + '</span>';

                        // If we've added to updater, reload to show in list
                        if (addToUpdater) {
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        statusEl.innerHTML = '<span class="error">' + dd_github_updates.install_error + response.data + '</span>';
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusEl.innerHTML = '<span class="error">' + dd_github_updates.install_error + 'Server error occurred. Check your server logs and browser console for details.' + '</span>';
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                });
            });
        }

        // Initialize page elements
        function initializePage() {
            // Trigger the repo type change to show/hide appropriate fields
            if (repoTypeSelect) {
                const event = new Event('change');
                repoTypeSelect.dispatchEvent(event);
            }

            if (installTypeSelect) {
                const event = new Event('change');
                installTypeSelect.dispatchEvent(event);
            }
        }

        // Run initialization
        initializePage();
    });
})();
