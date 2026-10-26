package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
)

// Target is what Flitops hands back for a valid token: where to connect and
// how to prove who we are and who the server is.
type Target struct {
	SessionID          int64  `json:"session_id"`
	Host               string `json:"host"`
	Port               int    `json:"port"`
	User               string `json:"user"`
	PrivateKey         string `json:"private_key"`
	HostKeyType        string `json:"host_key_type"`
	HostKeyFingerprint string `json:"host_key_fingerprint"`
}

// ErrTokenRejected means Flitops refused the token: unknown, expired, already
// used, or the server cannot be reached from the terminal.
var ErrTokenRejected = errors.New("terminal token rejected")

// FlitopsClient calls the internal terminal endpoints of the Flitops app.
type FlitopsClient struct {
	BaseURL string
	Secret  string
	HTTP    *http.Client
}

// Redeem exchanges a browser token for the connection target. Flitops marks
// the session started, so a token works only once.
func (c *FlitopsClient) Redeem(ctx context.Context, token string) (Target, error) {
	var target Target

	resp, err := c.post(ctx, "/internal/terminal-sessions/redeem", map[string]string{"token": token})
	if err != nil {
		return target, err
	}
	defer resp.Body.Close()

	switch {
	case resp.StatusCode == http.StatusForbidden || resp.StatusCode == http.StatusConflict || resp.StatusCode == http.StatusUnprocessableEntity:
		return target, ErrTokenRejected
	case resp.StatusCode != http.StatusOK:
		return target, fmt.Errorf("redeem: unexpected status %d", resp.StatusCode)
	}

	if err := json.NewDecoder(resp.Body).Decode(&target); err != nil {
		return target, fmt.Errorf("redeem: decoding response: %w", err)
	}

	return target, nil
}

// End records that the session closed.
func (c *FlitopsClient) End(ctx context.Context, sessionID int64) error {
	resp, err := c.post(ctx, fmt.Sprintf("/internal/terminal-sessions/%d/end", sessionID), nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusNoContent {
		return fmt.Errorf("end: unexpected status %d", resp.StatusCode)
	}

	return nil
}

func (c *FlitopsClient) post(ctx context.Context, path string, body any) (*http.Response, error) {
	payload, err := json.Marshal(body)
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.BaseURL+path, bytes.NewReader(payload))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.Secret)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	return c.HTTP.Do(req)
}
