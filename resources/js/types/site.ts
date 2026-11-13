import { Deployment } from './deployment';
import { Server } from './server';

export interface Site {
    id: number;
    ulid: string;
    domain: string;
    aliases: string[] | null;
    directory: string;
    /** The repository folder that holds the app; null for the top of the repository. */
    app_directory: string | null;
    root_path: string;
    web_root: string;
    system_user: string;
    is_isolated: boolean;
    repository: string | null;
    source_control_account_id: number | null;
    short_repository: string | null;
    repository_url: string | null;
    repository_provider: 'github' | 'gitlab' | 'bitbucket' | 'custom' | null;
    repository_provider_label: string | null;
    branch: string;
    project_type: 'laravel' | 'php' | 'html' | 'symfony' | 'wordpress' | null;
    project_type_label: string | null;
    framework: string | null;
    framework_label: string | null;
    php_version: string;
    php_binary: string | null;
    status: 'pending' | 'installing' | 'provisioned' | 'deployed' | 'deploying' | 'failed' | 'deleting' | 'isolating';
    status_label: string;
    status_color: 'gray' | 'blue' | 'teal' | 'green' | 'yellow' | 'red' | 'orange';
    error_message: string | null;
    creation_failed: boolean;
    uptime_status: 'up' | 'down' | null;
    uptime_status_label: string | null;
    uptime_status_color: 'green' | 'red' | null;
    uptime_checked_at: string | null;
    open_incident?: { reason: string; started_at: string } | null;
    provisioning_step?: { value: number; label: string; description: string } | null;
    provisioning_steps?: Array<{ value: number; label: string; description: string }>;
    auto_deploy: boolean;
    single_page_app: boolean;
    octane_server: 'frankenphp' | null;
    octane_port: number | null;
    server?: Server;
    latest_deployment?: Deployment;
    deployments?: Deployment[];
    deploy_script?: string;
    environment_variables?: EnvironmentVariable[];
    deployment_started_at: string | null;
    deployment_finished_at: string | null;
    created_at: string;
    updated_at: string;
}

/** What a site can be built with, as offered on the create-site page. */
export interface Framework {
    value: string;
    label: string;
    description: string;
    /** How the server serves it, e.g. `html` for every framework that builds to plain files. */
    projectType: string;
    defaultDirectory: string;
    buildsWithNode: boolean;
    singlePageApp: boolean;
}

/** What FlitOps worked out about a repository from its files. */
export interface DetectedFramework {
    framework: string;
    directory: string;
    package_manager: string | null;
    build_command: string | null;
    single_page_app: boolean;
    /** Something about the project that will stop the site working as it is. */
    warning: string | null;
}

export interface RepositoryProvider {
    value: string;
    label: string;
}

export interface PhpVersion {
    value: string;
    label: string;
}

export interface EnvironmentVariable {
    key: string;
    value: string;
}
