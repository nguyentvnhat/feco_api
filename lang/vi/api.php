<?php

return [
    'errors' => [
        'not_found' => 'Không tìm thấy.',
        'validation_error' => 'Dữ liệu không hợp lệ.',
    ],
    'auth' => [
        'invalid_credentials' => 'Thông tin đăng nhập không đúng.',
        'login_success' => 'Đăng nhập thành công.',
        'logout_success' => 'Đăng xuất thành công.',
        'account_not_found' => 'Tài khoản không tồn tại.',
        'account_not_activated' => 'Tài khoản chưa được kích hoạt, vui lòng liên hệ quản trị viên.',
        'account_locked' => 'Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên để mở lại.',
        'agent_policy_not_configured' => 'Chưa được thiết lập chính sách hoa hồng, vui lòng liên hệ quản trị viên.',
    ],
    'order' => [
        'index_success' => 'Lấy danh sách đơn hàng theo người dùng thành công.',
        'create_success' => 'Lấy dữ liệu tạo đơn hàng thành công.',
        'show_success' => 'Lấy chi tiết đơn hàng thành công.',
        'history_commission_success' => 'Lấy lịch sử hoa hồng đại lý thành công.',
        'history_commission_no_agent_profile' => 'Tài khoản chưa có hồ sơ đại lý để xem hoa hồng.',
        'statuses_success' => 'Lấy danh sách trạng thái đơn hàng thành công.',
        'store_success' => 'Tạo đơn hàng thành công.',
        'store_failed' => 'Có lỗi xảy ra khi tạo đơn hàng. Vui lòng thử lại.',
        'update_success' => 'Cập nhật đơn hàng thành công.',
        'destroy_success' => 'Xóa đơn hàng thành công.',
        'cancel_success' => 'Huỷ đơn hàng thành công.',
        'cancel_already_done' => 'Đơn hàng này đã ở trạng thái huỷ/hoàn.',
        'cancel_not_allowed' => 'Bạn không thể huỷ đơn :order_no. Vui lòng liên hệ Quản trị để được hỗ trợ.',
        'preview_success' => 'Xem trước đơn hàng thành công.',
        'clone_template_success' => 'Lấy dữ liệu đặt lại đơn hàng thành công.',
        'preview_no_agent_profile' => 'Tài khoản chưa có hồ sơ đại lý để áp dụng chiết khấu.',
        'agent_code_not_found' => 'Không tìm thấy mã đại lý của người dùng hiện tại để tạo mã đơn hàng.',
    ],
    'agent' => [
        'children_success' => 'Lấy danh sách đại lý con thành công.',
        'current_agent_not_found' => 'Tài khoản chưa được gắn với đại lý.',
    ],
    'setting' => [
        'index_success' => 'Lấy danh sách cấu hình thành công.',
    ],
];