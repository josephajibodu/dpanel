// Command terminal-gateway bridges browser terminals to SSH sessions on
// Flitops-managed servers.
//
// The browser opens a WebSocket here and sends the single-use token Flitops
// issued it. The gateway redeems the token with Flitops (which checks who may
// open a terminal and records the session), opens an SSH shell on the server,
// then copies keystrokes and output between the two until either side closes.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

// Config is read from environment variables at startup.
type Config struct {
	ListenAddr     string        // LISTEN_ADDR, where the gateway listens (behind nginx)
	FlitopsURL     string        // FLITOPS_URL, base URL of the Flitops app for the internal endpoints
	Secret         string        // GATEWAY_SECRET, or read from the file named by GATEWAY_SECRET_FILE
	AllowedOrigins []string      // ALLOWED_ORIGINS, comma-separated hosts the browser page may be served from
	IdleTimeout    time.Duration // IDLE_TIMEOUT, close the session after this long without keystrokes
	MaxSession     time.Duration // MAX_SESSION, close the session after this long regardless
}

func loadConfig() (Config, error) {
	cfg := Config{
		ListenAddr: envOr("LISTEN_ADDR", "127.0.0.1:8090"),
		FlitopsURL: strings.TrimRight(os.Getenv("FLITOPS_URL"), "/"),
		Secret:     os.Getenv("GATEWAY_SECRET"),
	}

	if file := os.Getenv("GATEWAY_SECRET_FILE"); file != "" {
		contents, err := os.ReadFile(file)
		if err != nil {
			return cfg, fmt.Errorf("reading GATEWAY_SECRET_FILE: %w", err)
		}
		cfg.Secret = strings.TrimSpace(string(contents))
	}

	for _, origin := range strings.Split(os.Getenv("ALLOWED_ORIGINS"), ",") {
		if origin = strings.TrimSpace(origin); origin != "" {
			cfg.AllowedOrigins = append(cfg.AllowedOrigins, origin)
		}
	}

	var err error
	if cfg.IdleTimeout, err = time.ParseDuration(envOr("IDLE_TIMEOUT", "15m")); err != nil {
		return cfg, fmt.Errorf("IDLE_TIMEOUT: %w", err)
	}
	if cfg.MaxSession, err = time.ParseDuration(envOr("MAX_SESSION", "2h")); err != nil {
		return cfg, fmt.Errorf("MAX_SESSION: %w", err)
	}

	switch {
	case cfg.FlitopsURL == "":
		return cfg, errors.New("FLITOPS_URL is required")
	case cfg.Secret == "":
		return cfg, errors.New("GATEWAY_SECRET or GATEWAY_SECRET_FILE is required")
	case len(cfg.AllowedOrigins) == 0:
		return cfg, errors.New("ALLOWED_ORIGINS is required")
	}

	return cfg, nil
}

func envOr(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}

func main() {
	logger := slog.New(slog.NewTextHandler(os.Stdout, nil))

	cfg, err := loadConfig()
	if err != nil {
		logger.Error("invalid configuration", "error", err)
		os.Exit(1)
	}

	gateway := &Gateway{
		cfg:     cfg,
		flitops: &FlitopsClient{BaseURL: cfg.FlitopsURL, Secret: cfg.Secret, HTTP: &http.Client{Timeout: 10 * time.Second}},
		logger:  logger,
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /terminal", gateway.HandleTerminal)
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("ok"))
	})

	server := &http.Server{Addr: cfg.ListenAddr, Handler: mux, ReadHeaderTimeout: 10 * time.Second}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	go func() {
		<-ctx.Done()
		// Open terminals are hijacked connections that Shutdown does not wait
		// for; supervisord restarts us and browsers can reconnect.
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		server.Shutdown(shutdownCtx)
	}()

	logger.Info("terminal gateway listening", "addr", cfg.ListenAddr)
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		logger.Error("server stopped", "error", err)
		os.Exit(1)
	}
}
