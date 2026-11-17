#SNApp Send event Script 

SERVER="http://10.130.148.146"
TOKEN_FILE="/home/snapp/networking/device_token.txt"
MAC=$(cat /sys/class/net/wlan0/address)

if [ ! -f "$TOKEN_FILE" ]; then
	echo "No token found - run register_device.sh first"
	exit 1

fi

TOKEN=$(cat $TOKEN_FILE)

echo "Sending event to backend..."

RESPONSE=$(curl -s -X POST $SERVER/event \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "{\"deviceID\": \"$MAC\", \"soundName\": \"Micro\", \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\", \"event\": \"sound_detected\"}")

echo "Server response: $RESPONSE"

