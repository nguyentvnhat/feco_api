<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Đại lý đã huỷ đơn hàng</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #0f172a; line-height: 1.5;">
    <p>Chào {{ $directEmployeeName }},</p>

    <p>Đại lý bạn đang trực tiếp phụ trách vừa huỷ một đơn hàng trên hệ thống.</p>

    <ul>
        <li><strong>Mã đơn:</strong> {{ $orderNo }}</li>
        <li><strong>Thời gian huỷ:</strong> {{ $cancelledAt }}</li>
        <li><strong>Đại lý:</strong> {{ $agentName }} ({{ $agentCode }})</li>
        <li><strong>Khách hàng:</strong> {{ $customerName }}</li>
        <li><strong>Giá trị đơn:</strong> {{ $netAmountFormatted }} {{ $currency }}</li>
    </ul>

    <p>Vui lòng kiểm tra thông tin để hỗ trợ xử lý nếu cần.</p>

    <p>Trân trọng,<br>Hệ thống FECO</p>
</body>
</html>

