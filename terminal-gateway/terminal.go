package main

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"strconv"
	"time"

	"github.com/coder/websocket"
	"golang.org/x/crypto/ssh"
)

// Close codes the browser shows a message for. 4000-4999 are reserved for
// applications by the WebSocket spec.
const (
	closeTokenRejected websocket.StatusCode = 4001
	closeConnectFailed websocket.StatusCode = 4002
	closeIdle          websocket.StatusCode = 4003
	closeMaxSession    websocket.StatusCode = 4004
)

const (
	authTimeout    = 10 * time.Second
	sshDialTimeout = 10 * time.Second
	maxMessageSize = 64 * 1024
	defaultCols    = 80
	defaultRows    = 24
)

// clientMessage is a JSON text frame from the browser. Keystrokes arrive as
// binary frames instead, so they never need escaping.
type clientMessage struct {
	Type  string `json:"type"` // "auth" (first message only) or "resize"
	Token string `json:"token,omitempty"`
	Cols  int    `json:"cols"`
	Rows  int    `json:"rows"`
}

// Gateway serves browser terminals.
type Gateway struct {
	cfg     Config
	flitops *FlitopsClient
	logger  *slog.Logger
}

// HandleTerminal runs one terminal session for the lifetime of the WebSocket.
func (g *Gateway) HandleTerminal(w http.ResponseWriter, r *http.Request) {
	ws, err := websocket.Accept(w, r, &websocket.AcceptOptions{OriginPatterns: g.cfg.AllowedOrigins})
	if err != nil {
		return // Accept already answered, e.g. 403 for a foreign origin
	}
	defer ws.CloseNow()
	ws.SetReadLimit(maxMessageSize)

	ctx := r.Context()

	// The token travels in the first message rather than the URL so it never
	// lands in proxy access logs.
	authCtx, cancelAuth := context.WithTimeout(ctx, authTimeout)
	auth, err := readControl(authCtx, ws)
	cancelAuth()
	if err != nil || auth.Type != "auth" || auth.Token == "" {
		ws.Close(closeTokenRejected, "Expected a terminal token.")
		return
	}

	target, err := g.flitops.Redeem(ctx, auth.Token)
	if errors.Is(err, ErrTokenRejected) {
		ws.Close(closeTokenRejected, "This terminal link has expired. Open a new terminal.")
		return
	}
	if err != nil {
		g.logger.Error("redeeming terminal token failed", "error", err)
		ws.Close(websocket.StatusInternalError, "Could not reach Flitops.")
		return
	}

	logger := g.logger.With("session_id", target.SessionID, "host", target.Host)
	logger.Info("terminal session started")
	defer func() {
		endCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		if err := g.flitops.End(endCtx, target.SessionID); err != nil {
			logger.Error("recording session end failed", "error", err)
		}
		logger.Info("terminal session ended")
	}()

	shell, err := openShell(target, auth.Cols, auth.Rows)
	if err != nil {
		logger.Warn("opening shell failed", "error", err)
		ws.Close(closeConnectFailed, "Could not open a shell on the server.")
		return
	}
	defer shell.Close()

	code, reason := g.pipe(ctx, ws, shell)
	ws.Close(code, reason)
}

// pipe copies bytes both ways until the shell exits, the browser leaves, the
// user goes idle or the session reaches its time limit.
func (g *Gateway) pipe(ctx context.Context, ws *websocket.Conn, shell *Shell) (websocket.StatusCode, string) {
	// The limits are timers rather than a context deadline: cancelling the
	// context of a pending read drops the connection before the close
	// message telling the browser why could be sent.
	idle := time.NewTimer(g.cfg.IdleTimeout)
	defer idle.Stop()
	limit := time.NewTimer(g.cfg.MaxSession)
	defer limit.Stop()

	outputDone := make(chan struct{})
	go func() {
		defer close(outputDone)
		buf := make([]byte, 32*1024)
		for {
			n, err := shell.Stdout.Read(buf)
			if n > 0 {
				if ws.Write(ctx, websocket.MessageBinary, buf[:n]) != nil {
					return
				}
			}
			if err != nil {
				return
			}
		}
	}()

	activity := make(chan struct{}, 1)
	inputDone := make(chan struct{})
	go func() {
		defer close(inputDone)
		for {
			kind, data, err := ws.Read(ctx)
			if err != nil {
				return
			}

			select {
			case activity <- struct{}{}:
			default:
			}

			if kind == websocket.MessageBinary {
				if _, err := shell.Stdin.Write(data); err != nil {
					return
				}
				continue
			}

			var msg clientMessage
			if json.Unmarshal(data, &msg) == nil && msg.Type == "resize" {
				cols, rows := clampSize(msg.Cols, msg.Rows)
				shell.Session.WindowChange(rows, cols)
			}
		}
	}()

	for {
		select {
		case <-activity:
			idle.Reset(g.cfg.IdleTimeout)
		case <-outputDone:
			return websocket.StatusNormalClosure, "The shell exited."
		case <-inputDone:
			return websocket.StatusNormalClosure, ""
		case <-idle.C:
			return closeIdle, "Closed after " + g.cfg.IdleTimeout.String() + " without input."
		case <-limit.C:
			return closeMaxSession, "Closed after reaching the " + g.cfg.MaxSession.String() + " session limit."
		}
	}
}

func readControl(ctx context.Context, ws *websocket.Conn) (clientMessage, error) {
	var msg clientMessage

	kind, data, err := ws.Read(ctx)
	if err != nil {
		return msg, err
	}
	if kind != websocket.MessageText {
		return msg, errors.New("expected a text message")
	}

	return msg, json.Unmarshal(data, &msg)
}

func clampSize(cols, rows int) (int, int) {
	if cols < 1 || cols > 1000 {
		cols = defaultCols
	}
	if rows < 1 || rows > 1000 {
		rows = defaultRows
	}
	return cols, rows
}

// Shell is an interactive login shell on a server.
type Shell struct {
	client  *ssh.Client
	Session *ssh.Session
	Stdin   io.WriteCloser
	Stdout  io.Reader
}

func (s *Shell) Close() {
	s.Session.Close()
	s.client.Close()
}

// openShell connects as the server user, refusing any server whose host key
// does not match the one Flitops recorded, and starts a shell on a PTY.
func openShell(target Target, cols, rows int) (*Shell, error) {
	signer, err := ssh.ParsePrivateKey([]byte(target.PrivateKey))
	if err != nil {
		return nil, fmt.Errorf("parsing private key: %w", err)
	}

	client, err := ssh.Dial("tcp", net.JoinHostPort(target.Host, strconv.Itoa(target.Port)), &ssh.ClientConfig{
		User:              target.User,
		Auth:              []ssh.AuthMethod{ssh.PublicKeys(signer)},
		HostKeyCallback:   pinnedHostKey(target.HostKeyFingerprint),
		HostKeyAlgorithms: hostKeyAlgorithms(target.HostKeyType),
		Timeout:           sshDialTimeout,
	})
	if err != nil {
		return nil, fmt.Errorf("connecting: %w", err)
	}

	session, err := client.NewSession()
	if err != nil {
		client.Close()
		return nil, fmt.Errorf("opening session: %w", err)
	}

	shell := &Shell{client: client, Session: session}
	if shell.Stdin, err = session.StdinPipe(); err == nil {
		shell.Stdout, err = session.StdoutPipe()
	}
	if err != nil {
		shell.Close()
		return nil, fmt.Errorf("attaching pipes: %w", err)
	}

	cols, rows = clampSize(cols, rows)
	if err := session.RequestPty("xterm-256color", rows, cols, ssh.TerminalModes{ssh.ECHO: 1}); err != nil {
		shell.Close()
		return nil, fmt.Errorf("requesting pty: %w", err)
	}
	if err := session.Shell(); err != nil {
		shell.Close()
		return nil, fmt.Errorf("starting shell: %w", err)
	}

	return shell, nil
}

// pinnedHostKey accepts only the host key whose SHA256 fingerprint Flitops
// recorded, in the same format as `ssh-keygen -lf`.
func pinnedHostKey(expected string) ssh.HostKeyCallback {
	return func(_ string, _ net.Addr, key ssh.PublicKey) error {
		presented := ssh.FingerprintSHA256(key)
		if subtle.ConstantTimeCompare([]byte(presented), []byte(expected)) != 1 {
			return fmt.Errorf("host key mismatch: expected %s, server presented %s", expected, presented)
		}
		return nil
	}
}

// hostKeyAlgorithms asks the server for the same kind of key Flitops pinned.
// A server usually holds several (ed25519, ecdsa, rsa); without this the
// handshake could pick a different one and look like a mismatch.
func hostKeyAlgorithms(keyType string) []string {
	switch keyType {
	case "":
		return nil
	case ssh.KeyAlgoRSA:
		return []string{ssh.KeyAlgoRSASHA512, ssh.KeyAlgoRSASHA256, ssh.KeyAlgoRSA}
	default:
		return []string{keyType}
	}
}
