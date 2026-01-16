import { Deployment } from './deployment';
import { Server } from './server';

export interface Site {
    id: number;
    ulid: string;
    domain: string;
    aliases: string[] | null;
    directory: string;
    root_path: string;
    web_root: string;
    repository: string | null;
    short_repository: string | null;
    repository_url: string | null;
    repository_provider: 'github' | 'gitlab' | 'bitbucket' | 'custom' | null;
    repository_provider_label: string | null;
    branch: string;
    project_type: 'laravel' | 'php' | 'html' | 'symfony' | 'wordpress' | null;
    project_type_label: string | null;
    php_version: string;
    php_binary: string | null;
    status: 'pending' | 'installing' | 'deployed' | 'deploying' | 'failed';
    status_label: string;
    status_color: 'gray' | 'blue' | 'green' | 'yellow' | 'red';
    auto_deploy: boolean;
    server?: Server;
    latest_deployment?: Deployment;
    deployments?: Deployment[];
    deploy_script?: string;
    deployment_started_at: string | null;
    deployment_finished_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ProjectType {
    value: string;
    label: string;
    defaultDirectory: string;
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
