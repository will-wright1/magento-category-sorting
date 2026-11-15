#!/usr/bin/env python3
"""Run ranking integration tests in disposable localhost MySQL/OpenSearch containers."""

import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import time
import urllib.request
import uuid


def main():
    root = Path(__file__).resolve().parents[1]
    magento_root = Path(os.environ.get("MAGENTO_ROOT", root)).resolve()
    php = os.environ.get("PHP_BIN", "php")
    phpunit = magento_root / "vendor/bin/phpunit"
    if not phpunit.is_file():
        raise RuntimeError("Set MAGENTO_ROOT to a Magento installation with PHPUnit dependencies.")
    suffix = uuid.uuid4().hex[:10]
    created = []
    with tempfile.TemporaryDirectory(prefix="category-sorting-") as directory:
        directory = Path(directory)
        password = secrets.token_hex(24)
        env_file = directory / "mysql.env"
        env_file.write_text("MYSQL_ROOT_PASSWORD=" + password + "\nMYSQL_DATABASE=ranking_test\n")
        env_file.chmod(0o600)
        services = {"mysql_password": password}
        definitions = [
            ("mysql", "3306/tcp", ["--env-file", str(env_file), "-p", "127.0.0.1::3306", "mysql:8.4",
                "--performance-schema=OFF", "--innodb-buffer-pool-size=32M", "--max-connections=20"]),
            ("search", "9200/tcp", [
                "-e", "discovery.type=single-node", "-e", "DISABLE_SECURITY_PLUGIN=true",
                "-e", "DISABLE_INSTALL_DEMO_CONFIG=true", "-e", "DISABLE_PERFORMANCE_ANALYZER_AGENT_CLI=true",
                "-e", "OPENSEARCH_JAVA_OPTS=-Xms512m -Xmx512m -XX:ActiveProcessorCount=2",
                "-p", "127.0.0.1::9200", "opensearchproject/opensearch:3.2.0",
            ]),
        ]
        config = directory / "services.json"
        env = os.environ | {"CATEGORY_SORTING_TEST_CONFIG": str(config)}

        def mysql_ready():
            result = subprocess.run([php, "-r", """
                $s=json_decode(file_get_contents(getenv('CATEGORY_SORTING_TEST_CONFIG')),true);
                try {$pdo=new PDO('mysql:host=127.0.0.1;port='.$s['mysql_port'].';dbname=ranking_test',
                    'root',$s['mysql_password'],[PDO::ATTR_TIMEOUT=>2]); exit(0);}
                catch(Throwable $e) {exit(1);}
            """], cwd=root, env=env, capture_output=True, timeout=5)
            return result.returncode == 0

        def wait_until_ready(check, name):
            deadline = time.monotonic() + 240
            next_update = 0
            while time.monotonic() < deadline:
                try:
                    if check():
                        return
                except (OSError, subprocess.TimeoutExpired):
                    pass
                if time.monotonic() >= next_update:
                    print("Waiting for isolated " + name + " service...", flush=True)
                    next_update = time.monotonic() + 15
                time.sleep(1)
            raise RuntimeError(name + " test service did not become ready within 240 seconds.")

        try:
            for service, port, arguments in definitions:
                name = "category-sorting-" + service + "-" + suffix
                print("Starting isolated " + service + " service...", flush=True)
                subprocess.run(["docker", "run", "--detach", "--init", "--rm", "--name", name,
                    "--label", "codex.task=category-sorting-test", *arguments],
                    check=True, capture_output=True, text=True, timeout=120)
                created.append(name)
                metadata = json.loads(subprocess.check_output(["docker", "inspect", name], text=True, timeout=30))[0]
                services[service + "_port"] = metadata["NetworkSettings"]["Ports"][port][0]["HostPort"]
                config.write_text(json.dumps(services))
                config.chmod(0o600)
                if service == "mysql":
                    wait_until_ready(mysql_ready, "MySQL")
                else:
                    def search_ready():
                        urllib.request.urlopen("http://127.0.0.1:" + services["search_port"], timeout=2).close()
                        return True
                    wait_until_ready(search_ready, "OpenSearch")
            return subprocess.run([php, str(phpunit), "-c", str(root / "phpunit.xml.dist"),
                "--testsuite", "integration", "--display-deprecations"], cwd=root, env=env).returncode
        finally:
            for name in reversed(created):
                try:
                    result = subprocess.run(["docker", "rm", "--force", name], capture_output=True, timeout=30)
                    if result.returncode:
                        print("Docker could not remove test container: " + name, flush=True)
                except subprocess.TimeoutExpired:
                    # Keep cleaning the remaining containers; do not mask an earlier test failure.
                    print("Docker cleanup timed out for test container: " + name, flush=True)


if __name__ == "__main__":
    raise SystemExit(main())
