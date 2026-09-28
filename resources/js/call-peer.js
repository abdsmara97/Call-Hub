/**
 * The peer connection, and the negotiation rules that keep it from wedging.
 *
 * This uses the "perfect negotiation" pattern from the WebRTC spec rather than
 * a hand-rolled "only the caller may renegotiate" lock. The reason is the video
 * toggle: if both people turn their camera on within the same second, both
 * start an offer, both receive the other's offer mid-flight, and without this
 * pattern one side throws InvalidStateError and the connection is left stuck in
 * have-local-offer. That kills the *audio* call, which was working — a cosmetic
 * feature taking down the thing people actually rang for.
 */

/**
 * Politeness is derived from the two user ids, never from who dialled.
 *
 * Deriving it from caller/callee is tempting and wrong: after a glare collapse
 * (see call-machine.js) there is no caller, so both peers would compute the
 * same politeness — the one thing this pattern must never allow. User ids are
 * distinct, stable and known to both sides, so both reach opposite answers with
 * no negotiation at all.
 */
export function isPolite(meId, peerId) {
    return Number(meId) > Number(peerId);
}

/**
 * Should an incoming description be ignored?
 *
 * Extracted as a pure function purely so the decision table is testable — there
 * is no RTCPeerConnection in node, so anything left inline here could only be
 * verified by hand in a browser.
 */
export function shouldIgnoreOffer({
    polite,
    makingOffer,
    signalingState,
    settingRemoteAnswer,
    type,
}) {
    const readyForOffer = ! makingOffer && (signalingState === 'stable' || settingRemoteAnswer);
    const collision = type === 'offer' && ! readyForOffer;

    // The polite peer always yields; the impolite one always wins. Both know
    // which they are, so exactly one rolls back.
    return { collision, ignore: ! polite && collision };
}

/**
 * Video codec preferences.
 *
 * Chrome offers VP8, VP9, AV1, H264 at several profile levels, plus RTX and
 * FEC, and the resulting SDP can run past what the signalling socket will
 * carry. Narrowing to two widely supported codecs roughly halves it and costs
 * nothing anyone will notice on a 1:1 call.
 */
function preferCommonVideoCodecs(transceiver) {
    if (typeof RTCRtpSender === 'undefined' || ! RTCRtpSender.getCapabilities) return;
    if (! transceiver || ! transceiver.setCodecPreferences) return;

    const capabilities = RTCRtpSender.getCapabilities('video');

    if (! capabilities?.codecs) return;

    const preferred = capabilities.codecs.filter((codec) => /VP8|H264/i.test(codec.mimeType));

    if (preferred.length === 0) return;

    try {
        transceiver.setCodecPreferences(preferred);
    } catch {
        // Non-fatal: a browser that refuses simply negotiates its own way.
    }
}

export function createPeerConnection(iceServers) {
    return new RTCPeerConnection({
        iceServers,
        // Collapses per-media ICE and DTLS attributes into one bundle, which
        // both shrinks the SDP and halves the candidate gathering.
        bundlePolicy: 'max-bundle',
        rtcpMuxPolicy: 'require',
    });
}

/**
 * Wires negotiation onto a peer connection.
 *
 * @param {RTCPeerConnection} pc
 * @param {{send: (event: string, payload: object) => boolean}} signal
 * @param {boolean} polite
 * @param {(error: Error) => void} onError
 */
export function attachNegotiation(pc, signal, polite, onError) {
    let makingOffer = false;
    let ignoreOffer = false;
    let settingRemoteAnswer = false;

    /*
     * addIceCandidate rejects while remoteDescription is null, and candidates
     * are trickled on their own whispers, so nothing guarantees the offer wins
     * the race. Candidates that arrive early are held rather than thrown away —
     * losing them is how a call ends up negotiated but with no media path.
     */
    let earlyCandidates = [];

    async function addCandidate(candidate) {
        try {
            await pc.addIceCandidate(candidate);
        } catch (error) {
            // Candidates belonging to an offer we deliberately ignored are
            // expected to fail; anything else is worth surfacing.
            if (! ignoreOffer) onError(error);
        }
    }

    pc.onnegotiationneeded = async () => {
        try {
            makingOffer = true;

            await pc.setLocalDescription();

            signal.send('call-offer', {
                sdp: pc.localDescription.sdp,
                type: pc.localDescription.type,
            });
        } catch (error) {
            onError(error);
        } finally {
            makingOffer = false;
        }
    };

    // One candidate per whisper. Bundling them into the offer instead would
    // push it past the socket's message limit, and the null end-of-gathering
    // candidate carries nothing the peer needs.
    pc.onicecandidate = ({ candidate }) => {
        if (candidate) {
            signal.send('call-ice', { candidate: candidate.toJSON() });
        }
    };

    return {
        async onDescription({ type, sdp }) {
            try {
                const { ignore } = shouldIgnoreOffer({
                    polite,
                    makingOffer,
                    signalingState: pc.signalingState,
                    settingRemoteAnswer,
                    type,
                });

                ignoreOffer = ignore;

                if (ignore) return;

                settingRemoteAnswer = type === 'answer';

                // Implicit rollback: on the polite side this discards our own
                // in-flight offer rather than erroring.
                await pc.setRemoteDescription({ type, sdp });

                settingRemoteAnswer = false;

                // There is somewhere to put them now.
                const held = earlyCandidates;
                earlyCandidates = [];
                await Promise.all(held.map(addCandidate));

                if (type === 'offer') {
                    await pc.setLocalDescription();

                    signal.send('call-answer', {
                        sdp: pc.localDescription.sdp,
                        type: pc.localDescription.type,
                    });
                }
            } catch (error) {
                onError(error);
            }
        },

        async onCandidate(candidate) {
            if (! pc.remoteDescription) {
                earlyCandidates.push(candidate);

                return;
            }

            await addCandidate(candidate);
        },

        preferCommonVideoCodecs,
    };
}
