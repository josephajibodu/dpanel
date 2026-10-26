package main

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/coder/websocket"
	"golang.org/x/crypto/ssh"
)

// fakeFlitops stands in for the Laravel internal endpoints.
type fakeFlitops struct {
	mu       sync.Mutex
	target   Target
	token    string
	redeemed bool
	ended    []int64
}

func (f *fakeFlitops) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()

	if r.Header.Get("Authorization") != "Bearer gateway-secret" {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}

	switch {
	case r.URL.Path == "/internal/terminal-sessions/redeem":
		var body struct{ Token string }
		json.NewDecoder(r.Body).Decode(&body)
		if body.Token != f.token || f.redeemed {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		f.redeemed = true
		json.NewEncoder(w).Encode(f.target)
	case strings.HasSuffix(r.URL.Path, "/end"):
		id, _ := strconv.ParseInt(strings.Split(r.URL.Path, "/")[3], 10, 64)
		f.ended = append(f.ended, id)
		w.WriteHeader(http.StatusNoContent)
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

func (f *fakeFlitops) endedSessions() []int64 {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]int64(nil), f.ended...)
}

// startSSHServer runs an SSH server that only accepts clientKey, and whose
// shell echoes input back and exits on "exit\r".
func startSSHServer(t *testing.T, clientKey ssh.PublicKey) (addr string, hostKey ssh.PublicKey) {
	t.Helper()

	_, hostPriv, _ := ed25519.GenerateKey(rand.Reader)
	hostSigner, _ := ssh.NewSignerFromKey(hostPriv)

	config := &ssh.ServerConfig{
		PublicKeyCallback: func(_ ssh.ConnMetadata, key ssh.PublicKey) (*ssh.Permissions, error) {
			if string(key.Marshal()) != string(clientKey.Marshal()) {
				return nil, io.EOF
			}
			return nil, nil
		},
	}
	config.AddHostKey(hostSigner)

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { listener.Close() })

	go func() {
		for {
			conn, err := listener.Accept()
			if err != nil {
				return
			}
			go serveSSH(conn, config)
		}
	}()

	return listener.Addr().String(), hostSigner.PublicKey()
}

func serveSSH(conn net.Conn, config *ssh.ServerConfig) {
	_, channels, requests, err := ssh.NewServerConn(conn, config)
	if err != nil {
		return
	}
	go ssh.DiscardRequests(requests)

	for newChannel := range channels {
		channel, channelRequests, _ := newChannel.Accept()
		go func() {
			for req := range channelRequests {
				req.Reply(req.Type == "pty-req" || req.Type == "shell" || req.Type == "window-change", nil)
			}
		}()
		go func() {
			defer channel.Close()
			buf := make([]byte, 1024)
			var typed string
			for {
				n, err := channel.Read(buf)
				if err != nil {
					return
				}
				channel.Write(buf[:n])
				typed += string(buf[:n])
				if strings.HasSuffix(typed, "exit\r") {
					channel.SendRequest("exit-status", false, ssh.Marshal(struct{ Status uint32 }{0}))
					return
				}
			}
		}()
	}
}

type harness struct {
	flitops *fakeFlitops
	url     string
}

func newHarness(t *testing.T, idleTimeout time.Duration, tamperHostKey bool) *harness {
	t.Helper()

	_, clientPriv, _ := ed25519.GenerateKey(rand.Reader)
	clientSigner, _ := ssh.NewSignerFromKey(clientPriv)
	block, _ := ssh.MarshalPrivateKey(clientPriv, "")

	sshAddr, hostKey := startSSHServer(t, clientSigner.PublicKey())
	host, portText, _ := net.SplitHostPort(sshAddr)
	port, _ := strconv.Atoi(portText)

	fingerprint := ssh.FingerprintSHA256(hostKey)
	if tamperHostKey {
		fingerprint = "SHA256:not-the-real-key"
	}

	flitops := &fakeFlitops{
		token: "valid-token",
		target: Target{
			SessionID:          42,
			Host:               host,
			Port:               port,
			User:               "artisan",
			PrivateKey:         string(pem.EncodeToMemory(block)),
			HostKeyType:        hostKey.Type(),
			HostKeyFingerprint: fingerprint,
		},
	}
	flitopsServer := httptest.NewServer(flitops)
	t.Cleanup(flitopsServer.Close)

	gateway := &Gateway{
		cfg: Config{
			AllowedOrigins: []string{"flitops.test"},
			IdleTimeout:    idleTimeout,
			MaxSession:     time.Minute,
		},
		flitops: &FlitopsClient{BaseURL: flitopsServer.URL, Secret: "gateway-secret", HTTP: http.DefaultClient},
		logger:  slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
	gatewayServer := httptest.NewServer(http.HandlerFunc(gateway.HandleTerminal))
	t.Cleanup(gatewayServer.Close)

	return &harness{flitops: flitops, url: "ws" + strings.TrimPrefix(gatewayServer.URL, "http")}
}

func (h *harness) dial(t *testing.T, ctx context.Context, origin string) *websocket.Conn {
	t.Helper()
	ws, _, err := websocket.Dial(ctx, h.url, &websocket.DialOptions{HTTPHeader: http.Header{"Origin": {origin}}})
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	t.Cleanup(func() { ws.CloseNow() })
	return ws
}

func sendAuth(t *testing.T, ctx context.Context, ws *websocket.Conn, token string) {
	t.Helper()
	payload, _ := json.Marshal(clientMessage{Type: "auth", Token: token, Cols: 120, Rows: 40})
	if err := ws.Write(ctx, websocket.MessageText, payload); err != nil {
		t.Fatal(err)
	}
}

// readUntilClosed collects output until the gateway closes the connection.
func readUntilClosed(ctx context.Context, ws *websocket.Conn) (string, websocket.CloseError) {
	var output strings.Builder
	for {
		_, data, err := ws.Read(ctx)
		if err != nil {
			var closeErr websocket.CloseError
			if !errors.As(err, &closeErr) {
				closeErr.Reason = err.Error()
				closeErr.Code = -1
			}
			return output.String(), closeErr
		}
		output.Write(data)
	}
}

func testContext(t *testing.T) context.Context {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	t.Cleanup(cancel)
	return ctx
}

func TestRelaysShellAndRecordsSessionEnd(t *testing.T) {
	h := newHarness(t, time.Minute, false)
	ctx := testContext(t)
	ws := h.dial(t, ctx, "https://flitops.test")

	sendAuth(t, ctx, ws, "valid-token")
	resize, _ := json.Marshal(clientMessage{Type: "resize", Cols: 100, Rows: 30})
	ws.Write(ctx, websocket.MessageText, resize)
	ws.Write(ctx, websocket.MessageBinary, []byte("echo hi\r"))
	ws.Write(ctx, websocket.MessageBinary, []byte("exit\r"))

	output, closed := readUntilClosed(ctx, ws)

	if !strings.Contains(output, "echo hi\r") {
		t.Errorf("expected the shell output to be relayed, got %q", output)
	}
	if closed.Code != websocket.StatusNormalClosure {
		t.Errorf("expected a normal close, got %d %q", closed.Code, closed.Reason)
	}
	waitForEnded(t, h.flitops, []int64{42})
}

func TestRejectsAnInvalidToken(t *testing.T) {
	h := newHarness(t, time.Minute, false)
	ctx := testContext(t)
	ws := h.dial(t, ctx, "https://flitops.test")

	sendAuth(t, ctx, ws, "wrong-token")
	_, closed := readUntilClosed(ctx, ws)

	if closed.Code != closeTokenRejected {
		t.Errorf("expected close code %d, got %d %q", closeTokenRejected, closed.Code, closed.Reason)
	}
	if ended := h.flitops.endedSessions(); len(ended) != 0 {
		t.Errorf("no session should be recorded as ended, got %v", ended)
	}
}

func TestRefusesAServerWithTheWrongHostKey(t *testing.T) {
	h := newHarness(t, time.Minute, true)
	ctx := testContext(t)
	ws := h.dial(t, ctx, "https://flitops.test")

	sendAuth(t, ctx, ws, "valid-token")
	output, closed := readUntilClosed(ctx, ws)

	if closed.Code != closeConnectFailed {
		t.Errorf("expected close code %d, got %d %q", closeConnectFailed, closed.Code, closed.Reason)
	}
	if output != "" {
		t.Errorf("expected no shell output, got %q", output)
	}
	waitForEnded(t, h.flitops, []int64{42})
}

func TestClosesAnIdleSession(t *testing.T) {
	h := newHarness(t, 200*time.Millisecond, false)
	ctx := testContext(t)
	ws := h.dial(t, ctx, "https://flitops.test")

	sendAuth(t, ctx, ws, "valid-token")
	_, closed := readUntilClosed(ctx, ws)

	if closed.Code != closeIdle {
		t.Errorf("expected close code %d, got %d %q", closeIdle, closed.Code, closed.Reason)
	}
	waitForEnded(t, h.flitops, []int64{42})
}

func TestRejectsAForeignOrigin(t *testing.T) {
	h := newHarness(t, time.Minute, false)
	ctx := testContext(t)

	_, resp, err := websocket.Dial(ctx, h.url, &websocket.DialOptions{HTTPHeader: http.Header{"Origin": {"https://evil.example"}}})

	if err == nil || resp == nil || resp.StatusCode != http.StatusForbidden {
		t.Fatalf("expected a 403 for a foreign origin, got err=%v resp=%v", err, resp)
	}
}

func TestPinnedHostKeyFingerprintMatchesSSHKeygenFormat(t *testing.T) {
	pub, _, _ := ed25519.GenerateKey(rand.Reader)
	key, _ := ssh.NewPublicKey(pub)

	if err := pinnedHostKey(ssh.FingerprintSHA256(key))("", nil, key); err != nil {
		t.Errorf("expected the matching fingerprint to be accepted: %v", err)
	}
	if err := pinnedHostKey("SHA256:something-else")("", nil, key); err == nil {
		t.Error("expected a different fingerprint to be refused")
	}
}

// waitForEnded allows for the end call, which runs as the handler returns.
func waitForEnded(t *testing.T, f *fakeFlitops, want []int64) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if got := f.endedSessions(); len(got) == len(want) && (len(want) == 0 || got[0] == want[0]) {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Errorf("expected ended sessions %v, got %v", want, f.endedSessions())
}
